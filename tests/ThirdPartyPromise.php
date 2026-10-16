<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise\Tests;

use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;

/**
 * A promise from another library that returns its own promises from then() and
 * counts cancel() calls.
 *
 * @implements PromiseInterface<mixed, mixed>
 */
class ThirdPartyPromise implements PromiseInterface
{
    public int $cancelCalls = 0;

    /** @var PromiseInterface<mixed, mixed> */
    private PromiseInterface $promise;

    /**
     * @param PromiseInterface<mixed, mixed>|null $promise
     */
    public function __construct(?PromiseInterface $promise = null)
    {
        $this->promise = $promise ?? new Promise();
    }

    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): PromiseInterface
    {
        return new self($this->promise->then($onFulfilled, $onRejected));
    }

    public function otherwise(callable $onRejected): PromiseInterface
    {
        return $this->then(null, $onRejected);
    }

    public function getState(): string
    {
        return $this->promise->getState();
    }

    public function resolve($value = null): void
    {
        $this->promise->resolve($value);
    }

    public function reject($reason): void
    {
        $this->promise->reject($reason);
    }

    public function wait(bool $unwrap = true)
    {
        return $this->promise->wait($unwrap);
    }

    public function cancel(): void
    {
        ++$this->cancelCalls;
        $this->promise->cancel();
    }
}
