<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise;

/**
 * Promises/A+ implementation that avoids recursion when possible.
 *
 * A pending Promise belongs to the Swoole coroutine that created it, or to code
 * running outside coroutines if it was created there. While the promise is
 * pending, only its owner may call then(), otherwise(), wait(), cancel(),
 * resolve(), or reject(). A call from another coroutine throws a LogicException
 * and leaves the promise unchanged. A Coroutine belongs to the coroutine that
 * created it in the same way.
 *
 * Resolving or rejecting a promise with a pending Promise or Coroutine that
 * another coroutine owns throws the same exception and also leaves the promise
 * unchanged. A promise that was resolved with a pending promise stays owned
 * until that promise settles.
 *
 * Once a promise and every promise it was resolved with have settled, any
 * coroutine may call then(), otherwise(), or wait() on it. Create and finish an
 * operation in one coroutine, then share its settled result.
 *
 * @template TValue = mixed
 * @template TReason = mixed
 *
 * @implements PromiseInterface<TValue, TReason>
 *
 * @see https://promisesaplus.com/
 *
 * @final
 */
class Promise implements PromiseInterface
{
    use NonSerializableTrait;

    /** @var self::PENDING|self::FULFILLED|self::REJECTED */
    private string $state = self::PENDING;

    /** @var TValue|TReason|PromiseInterface<TValue, TReason>|null */
    private $result;

    /** @var (callable(): void)|null */
    private $cancelFn;

    /** @var (callable(bool): void)|null */
    private $waitFn;

    /** @var list<Promise<mixed, mixed>>|null */
    private ?array $waitList = null;

    /** @var list<array{0: PromiseInterface<mixed, mixed>, 1: callable|null, 2: callable|null}>|null */
    private ?array $handlers = [];

    /** @var int Coroutine that created the promise, or -1 outside coroutines. */
    private int $owner;

    /**
     * @param (callable(bool): void)|null $waitFn   Fn that when invoked resolves the promise.
     * @param (callable(): void)|null     $cancelFn Fn that when invoked cancels the promise.
     */
    public function __construct(
        ?callable $waitFn = null,
        ?callable $cancelFn = null
    ) {
        $this->waitFn = $waitFn;
        $this->cancelFn = $cancelFn;
        $this->owner = \Swoole\Coroutine::getCid();
    }

    /**
     * @template TFulfilledValue = never
     * @template TFulfilledReason = never
     * @template TRejectedValue = never
     * @template TRejectedReason = never
     *
     * @param (callable(TValue): (TFulfilledValue|PromiseInterface<TFulfilledValue, TFulfilledReason>))|null $onFulfilled Invoked when the promise fulfills.
     * @param (callable(TReason): (TRejectedValue|PromiseInterface<TRejectedValue, TRejectedReason>))|null   $onRejected  Invoked when the promise is rejected.
     *
     * @return PromiseInterface<($onFulfilled is null ? TValue : TFulfilledValue)|($onRejected is null ? never : TRejectedValue), ($onFulfilled is null ? never : TFulfilledReason|\Throwable)|($onRejected is null ? TReason : TRejectedReason|\Throwable)>
     */
    public function then(
        ?callable $onFulfilled = null,
        ?callable $onRejected = null
    ): PromiseInterface {
        if ($this->owner !== \Swoole\Coroutine::getCid() && self::unfinished($this) !== null) {
            throw $this->foreignCall('then');
        }

        if ($this->state === self::PENDING) {
            $p = new Promise(null, [$this, 'cancel']);
            $this->handlers[] = [$p, $onFulfilled, $onRejected];
            $p->waitList = $this->waitList;
            $p->waitList[] = $this;

            return $p;
        }

        // Return a fulfilled promise and immediately invoke any callbacks.
        if ($this->state === self::FULFILLED) {
            $promise = Create::promiseFor($this->result);

            return $promise->then($onFulfilled, $onRejected);
        }

        // It's either cancelled or rejected, so return a rejected promise
        // and immediately invoke any callbacks.
        $rejection = Create::rejectionFor($this->result);

        /** @var PromiseInterface<($onFulfilled is null ? TValue : TFulfilledValue)|($onRejected is null ? never : TRejectedValue), ($onFulfilled is null ? never : TFulfilledReason|\Throwable)|($onRejected is null ? TReason : TRejectedReason|\Throwable)> $promise */
        $promise = $onRejected ? $rejection->then(null, $onRejected) : $rejection;

        return $promise;
    }

    /**
     * @template TRejectedValue = never
     * @template TRejectedReason = never
     *
     * @param callable(TReason): (TRejectedValue|PromiseInterface<TRejectedValue, TRejectedReason>) $onRejected Invoked when the promise is rejected.
     *
     * @return PromiseInterface<TValue|TRejectedValue, TRejectedReason|\Throwable>
     */
    public function otherwise(callable $onRejected): PromiseInterface
    {
        return $this->then(null, $onRejected);
    }

    public function wait(bool $unwrap = true)
    {
        if ($this->owner !== \Swoole\Coroutine::getCid() && self::unfinished($this) !== null) {
            throw $this->foreignCall('wait');
        }

        $this->waitIfPending();

        if ($this->result instanceof PromiseInterface) {
            return $this->result->wait($unwrap);
        }
        if ($unwrap) {
            if ($this->state === self::FULFILLED) {
                return $this->result;
            }
            // It's rejected so "unwrap" and throw an exception.
            throw Create::exceptionFor($this->result);
        }

        return null;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function cancel(): void
    {
        if ($this->state !== self::PENDING) {
            return;
        }

        if ($this->owner !== \Swoole\Coroutine::getCid()) {
            throw $this->foreignCall('cancel');
        }

        $this->waitFn = $this->waitList = null;

        if ($this->cancelFn) {
            $fn = $this->cancelFn;
            $this->cancelFn = null;
            try {
                $fn();
            } catch (\Throwable $e) {
                $this->reject($e);
            }
        }

        // Reject the promise only if it wasn't rejected in a then callback.
        /** @psalm-suppress RedundantCondition */
        if ($this->state === self::PENDING) {
            $this->reject(new CancellationException('Promise has been cancelled'));
        }
    }

    public function resolve($value = null): void
    {
        $this->settle(self::FULFILLED, $value);
    }

    public function reject($reason): void
    {
        $this->settle(self::REJECTED, $reason);
    }

    private function settle(string $state, $value): void
    {
        if ($this->state !== self::PENDING) {
            // Ignore calls with the same resolution.
            if ($state === $this->state && $value === $this->result) {
                return;
            }
            throw $this->state === $state
                ? new \LogicException("The promise is already {$state}.")
                : new \LogicException("Cannot change a {$this->state} promise to {$state}");
        }

        if ($value === $this) {
            throw new \LogicException('Cannot fulfill or reject a promise with itself');
        }

        // Check ownership before changing anything: the branches below merge
        // handlers into a pending promise directly, or chain onto the value
        // only after this promise has settled.
        $coroutine = \Swoole\Coroutine::getCid();
        if ($this->owner !== $coroutine) {
            throw $this->foreignCall($state === self::FULFILLED ? 'resolve' : 'reject');
        }
        $adopted = self::unfinished($value);
        if ($adopted === $this) {
            // The value's chain leads back here, so settling would create a
            // cycle that waits and later settlements could never leave.
            throw new \LogicException('Cannot fulfill or reject a promise with a promise that depends on it');
        }
        if ($adopted instanceof self && $adopted->owner !== $coroutine) {
            throw $adopted->foreignCall('adopt');
        }

        // Clear out the state of the promise but stash the handlers.
        $this->state = $state;
        $this->result = $value;
        $handlers = $this->handlers;
        $this->handlers = null;
        $this->waitList = $this->waitFn = null;
        $this->cancelFn = null;

        if (!$handlers) {
            return;
        }

        // If the value was not a settled promise or a thenable, then resolve
        // it in the task queue using the correct ID.
        if (!is_object($value) || !method_exists($value, 'then')) {
            $id = $state === self::FULFILLED ? 1 : 2;
            // It's a success, so resolve the handlers in the queue.
            Utils::queue()->add(static function () use ($id, $value, $handlers): void {
                foreach ($handlers as $handler) {
                    self::callHandler($id, $value, $handler);
                }
            });
        } elseif ($value instanceof Promise && Is::pending($value)) {
            // We can just merge our handlers onto the next promise.
            $value->handlers = array_merge($value->handlers, $handlers);
        } else {
            // Resolve the handlers when the forwarded promise is resolved.
            $value->then(
                static function ($value) use ($handlers): void {
                    foreach ($handlers as $handler) {
                        self::callHandler(1, $value, $handler);
                    }
                },
                static function ($reason) use ($handlers): void {
                    foreach ($handlers as $handler) {
                        self::callHandler(2, $reason, $handler);
                    }
                }
            );
        }
    }

    /**
     * Call a stack of handlers using a specific callback index and value.
     *
     * @param int   $index   1 (resolve) or 2 (reject).
     * @param mixed $value   Value to pass to the callback.
     * @param array $handler Array of handler data (promise and callbacks).
     */
    private static function callHandler(int $index, $value, array $handler): void
    {
        /** @var PromiseInterface<mixed, mixed> $promise */
        $promise = $handler[0];

        // The promise may have been cancelled or resolved before placing
        // this thunk in the queue.
        if (Is::settled($promise)) {
            return;
        }

        try {
            if (isset($handler[$index])) {
                /*
                 * If $f throws an exception, then $handler will be in the exception
                 * stack trace. Since $handler contains a reference to the callable
                 * itself we get a circular reference. We clear the $handler
                 * here to avoid that memory leak.
                 */
                $f = $handler[$index];
                unset($handler);
                $promise->resolve($f($value));
            } elseif ($index === 1) {
                // Forward resolution values as-is.
                $promise->resolve($value);
            } else {
                // Forward rejections down the chain.
                $promise->reject($value);
            }
        } catch (\Throwable $reason) {
            $promise->reject($reason);
        }
    }

    private function waitIfPending(): void
    {
        if ($this->state !== self::PENDING) {
            return;
        } elseif ($this->waitFn) {
            $this->invokeWaitFn();
        } elseif ($this->waitList) {
            $this->invokeWaitList();
        } else {
            // If there's no wait function, then reject the promise.
            $this->reject('Cannot wait on a promise that has '
                .'no internal wait function. You must provide a wait '
                .'function when constructing the promise to be able to '
                .'wait on a promise.');
        }

        Utils::queue()->run();

        /** @psalm-suppress RedundantCondition */
        if ($this->state === self::PENDING) {
            $this->reject('Invoking the wait callback did not resolve the promise');
        }
    }

    private function invokeWaitFn(): void
    {
        try {
            $wfn = $this->waitFn;
            $this->waitFn = null;
            $wfn(true);
        } catch (\Throwable $reason) {
            if ($this->state === self::PENDING) {
                // The promise has not been resolved yet, so reject the promise
                // with the exception.
                $this->reject($reason);
            } else {
                // The promise was already resolved, so there's a problem in
                // the application.
                throw $reason;
            }
        }
    }

    private function invokeWaitList(): void
    {
        $waitList = $this->waitList;
        $this->waitList = null;

        foreach ($waitList as $result) {
            do {
                $result->waitIfPending();
                $result = $result->result;
            } while ($result instanceof Promise);

            if ($result instanceof PromiseInterface) {
                $result->wait(false);
            }
        }
    }

    /**
     * Returns the promise whose owner must finish the given value, or null when
     * it and every promise it was resolved with have settled. That is the
     * pending Promise in the chain or, when the chain ends in a pending promise
     * from another implementation, the last Promise before it.
     *
     * @param mixed $value
     *
     * @return PromiseInterface<mixed, mixed>|null
     */
    private static function unfinished($value): ?PromiseInterface
    {
        $last = null;

        while (true) {
            if ($value instanceof Coroutine) {
                $value = $value->_getResult();
            }

            if (!$value instanceof self) {
                if (!$value instanceof PromiseInterface || $value->getState() !== self::PENDING) {
                    return null;
                }

                return $last ?? $value;
            }

            if ($value->state === self::PENDING) {
                return $value;
            }

            $last = $value;
            $value = $value->result;
        }
    }

    /**
     * @internal
     *
     * @param PromiseInterface<mixed, mixed> $promise
     */
    public static function _assertOwner(PromiseInterface $promise, string $method): void
    {
        if ($promise instanceof self && $promise->owner !== \Swoole\Coroutine::getCid()) {
            throw $promise->foreignCall($method);
        }
    }

    /**
     * Creates the exception thrown when the current coroutine uses a pending
     * promise that another coroutine owns.
     */
    private function foreignCall(string $method): \LogicException
    {
        return new \LogicException(\sprintf(
            'Coroutine %d cannot %s an unfinished promise owned by coroutine %d. Create and finish the operation in the same coroutine, then share its settled result.',
            \Swoole\Coroutine::getCid(),
            $method === 'adopt' ? 'settle a promise with' : "call {$method}() on",
            $this->owner
        ));
    }
}
