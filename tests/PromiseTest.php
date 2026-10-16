<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise\Tests;

use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\CancellationException;
use GuzzleHttp\Promise\Coroutine;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Promise\RejectionException;
use GuzzleHttp\Promise\TaskQueue;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Promise\Promise
 */
class PromiseTest extends TestCase
{
    public function testCannotResolveNonPendingPromise(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The promise is already fulfilled');

        $p = new Promise();
        $p->resolve('foo');
        $p->resolve('bar');
        $this->assertSame('foo', $p->wait());
    }

    public function testCanResolveWithSameValue(): void
    {
        $p = new Promise();
        $p->resolve('foo');
        $p->resolve('foo');
        $this->assertSame('foo', $p->wait());
    }

    public function testCanResolveWithoutValue(): void
    {
        $p = new Promise();
        $p->resolve();

        $this->assertTrue(P\Is::fulfilled($p));
        $this->assertNull($p->wait());
    }

    public function testCanResolveWithoutValueRepeatedly(): void
    {
        $p = new Promise();
        $p->resolve();
        $p->resolve(null);

        $this->assertNull($p->wait());
    }

    public function testCannotRejectNonPendingPromise(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot change a fulfilled promise to rejected');

        $p = new Promise();
        $p->resolve('foo');
        $p->reject('bar');
        $this->assertSame('foo', $p->wait());
    }

    public function testCanRejectWithSameValue(): void
    {
        $p = new Promise();
        $p->reject('foo');
        $p->reject('foo');
        $this->assertTrue(P\Is::rejected($p));
    }

    public function testCannotRejectResolveWithSameValue(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot change a fulfilled promise to rejected');

        $p = new Promise();
        $p->resolve('foo');
        $p->reject('foo');
    }

    public function testInvokesWaitFunction(): void
    {
        $p = new Promise(function () use (&$p): void {
            $p->resolve('10');
        });
        $this->assertSame('10', $p->wait());
    }

    public function testRejectsAndThrowsWhenWaitFailsToResolve(): void
    {
        $this->expectException(RejectionException::class);
        $this->expectExceptionMessage('The promise was rejected with reason: Invoking the wait callback did not resolve the promise');

        $p = new Promise(function (): void {});
        $p->wait();
    }

    public function testThrowsWhenUnwrapIsRejectedWithNonException(): void
    {
        $this->expectException(RejectionException::class);
        $this->expectExceptionMessage('The promise was rejected with reason: foo');

        $p = new Promise(function () use (&$p): void {
            $p->reject('foo');
        });
        $p->wait();
    }

    public function testThrowsWhenUnwrapIsRejectedWithException(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('foo');

        $e = new \UnexpectedValueException('foo');
        $p = new Promise(function () use (&$p, $e): void {
            $p->reject($e);
        });
        $p->wait();
    }

    public function testThrowsWhenUnwrapIsRejectedWithError(): void
    {
        $error = new \Error('foo');
        $p = new Promise(function () use (&$p, $error): void {
            $p->reject($error);
        });

        try {
            $p->wait();
            $this->fail('Expected Error');
        } catch (\Error $e) {
            $this->assertSame($error, $e);
        }
    }

    public function testDoesNotUnwrapExceptionsWhenDisabled(): void
    {
        $p = new Promise(function () use (&$p): void {
            $p->reject('foo');
        });
        $this->assertTrue(P\Is::pending($p));
        $p->wait(false);
        $this->assertTrue(P\Is::rejected($p));
    }

    public function testRejectsSelfWhenWaitThrows(): void
    {
        $e = new \UnexpectedValueException('foo');
        $p = new Promise(function () use ($e): void {
            throw $e;
        });
        try {
            $p->wait();
            $this->fail();
        } catch (\UnexpectedValueException $e) {
            $this->assertTrue(P\Is::rejected($p));
        }
    }

    public function testRejectsSelfWhenWaitThrowsError(): void
    {
        $error = new \Error('foo');
        $p = new Promise(function () use ($error): void {
            throw $error;
        });

        try {
            $p->wait();
            $this->fail('Expected Error');
        } catch (\Error $e) {
            $this->assertSame($error, $e);
            $this->assertTrue(P\Is::rejected($p));
        }
    }

    public function testWaitsOnNestedPromises(): void
    {
        $p = new Promise(function () use (&$p): void {
            $p->resolve('_');
        });
        $p2 = new Promise(function () use (&$p2): void {
            $p2->resolve('foo');
        });
        $p3 = $p->then(function () use ($p2): PromiseInterface {
            return $p2;
        });
        $this->assertSame('foo', $p3->wait());
    }

    public function testThrowsWhenWaitingOnPromiseWithNoWaitFunction(): void
    {
        $this->expectException(RejectionException::class);

        $p = new Promise();
        $p->wait();
    }

    public function testThrowsWaitExceptionAfterPromiseIsResolved(): void
    {
        $p = new Promise(function () use (&$p): void {
            $p->reject('Foo!');
            throw new \Exception('Bar?');
        });

        try {
            $p->wait();
            $this->fail();
        } catch (\Exception $e) {
            $this->assertSame('Bar?', $e->getMessage());
        }
    }

    public function testGetsActualWaitValueFromThen(): void
    {
        $p = new Promise(function () use (&$p): void {
            $p->reject('Foo!');
        });
        $p2 = $p->then(null, function (string $reason): PromiseInterface {
            return new RejectedPromise([$reason]);
        });

        try {
            $p2->wait();
            $this->fail('Should have thrown');
        } catch (RejectionException $e) {
            $this->assertSame(['Foo!'], $e->getReason());
        }
    }

    public function testWaitBehaviorIsBasedOnLastPromiseInChain(): void
    {
        $p3 = new Promise(function () use (&$p3): void {
            $p3->resolve('Whoop');
        });
        $p2 = new Promise(function () use (&$p2, $p3): void {
            $p2->reject($p3);
        });
        $p = new Promise(function () use (&$p, $p2): void {
            $p->reject($p2);
        });
        $this->assertSame('Whoop', $p->wait());
    }

    public function testWaitsOnAPromiseChainEvenWhenNotUnwrapped(): void
    {
        $p2 = new Promise(function () use (&$p2): void {
            $p2->reject('Fail');
        });
        $p = new Promise(function () use ($p2, &$p): void {
            $p->resolve($p2);
        });
        $p->wait(false);
        $this->assertTrue(P\Is::rejected($p2));
    }

    public function testCannotCancelNonPending(): void
    {
        $p = new Promise();
        $p->resolve('foo');
        $p->cancel();
        $this->assertTrue(P\Is::fulfilled($p));
    }

    public function testCancelsPromiseWhenNoCancelFunction(): void
    {
        $this->expectException(CancellationException::class);

        $p = new Promise();
        $p->cancel();
        $this->assertTrue(P\Is::rejected($p));
        $p->wait();
    }

    public function testCancelsPromiseWithCancelFunction(): void
    {
        $called = false;
        $p = new Promise(null, function () use (&$called): void {
            $called = true;
        });
        $p->cancel();
        $this->assertTrue(P\Is::rejected($p));
        $this->assertTrue($called);
    }

    public function testCancelsUppermostPendingPromise(): void
    {
        $called = false;
        $p1 = new Promise(null, function () use (&$called): void {
            $called = true;
        });
        $p2 = $p1->then(function (): void {});
        $p3 = $p2->then(function (): void {});
        $p4 = $p3->then(function (): void {});
        $p3->cancel();
        $this->assertTrue(P\Is::rejected($p1));
        $this->assertTrue(P\Is::rejected($p2));
        $this->assertTrue(P\Is::rejected($p3));
        $this->assertTrue(P\Is::pending($p4));
        $this->assertTrue($called);

        try {
            $p3->wait();
            $this->fail();
        } catch (CancellationException $e) {
            $this->assertStringContainsString('cancelled', $e->getMessage());
        }

        try {
            $p4->wait();
            $this->fail();
        } catch (CancellationException $e) {
            $this->assertStringContainsString('cancelled', $e->getMessage());
        }

        $this->assertTrue(P\Is::rejected($p4));
    }

    public function testCancelsChildPromises(): void
    {
        $called1 = $called2 = $called3 = false;
        $p1 = new Promise(null, function () use (&$called1): void {
            $called1 = true;
        });
        $p2 = new Promise(null, function () use (&$called2): void {
            $called2 = true;
        });
        $p3 = new Promise(null, function () use (&$called3): void {
            $called3 = true;
        });
        $p4 = $p2->then(function () use ($p3): PromiseInterface {
            return $p3;
        });
        $p5 = $p4->then(function (): void {
            $this->fail();
        });
        $p4->cancel();
        $this->assertTrue(P\Is::pending($p1));
        $this->assertTrue(P\Is::rejected($p2));
        $this->assertTrue(P\Is::pending($p3));
        $this->assertTrue(P\Is::rejected($p4));
        $this->assertTrue(P\Is::pending($p5));
        $this->assertFalse($called1);
        $this->assertTrue($called2);
        $this->assertFalse($called3);
    }

    public function testRejectsPromiseWhenCancelFails(): void
    {
        $called = false;
        $p = new Promise(null, function () use (&$called): void {
            $called = true;
            throw new \Exception('e');
        });
        $p->cancel();
        $this->assertTrue(P\Is::rejected($p));
        $this->assertTrue($called);
        try {
            $p->wait();
            $this->fail();
        } catch (\Exception $e) {
            $this->assertSame('e', $e->getMessage());
        }
    }

    public function testCreatesPromiseWhenFulfilledAfterThen(): void
    {
        $p = new Promise();
        $carry = null;
        $p2 = $p->then(function (string $v) use (&$carry): void {
            $carry = $v;
        });
        $this->assertNotSame($p, $p2);
        $p->resolve('foo');
        P\Utils::queue()->run();

        $this->assertSame('foo', $carry);
    }

    public function testResolveWithoutValueNotifiesCallbacksWithNull(): void
    {
        $p = new Promise();
        $called = false;
        $received = 'not called';

        $p->then(function ($value) use (&$called, &$received): void {
            $called = true;
            $received = $value;
        });

        $p->resolve();
        P\Utils::queue()->run();

        $this->assertTrue($called);
        $this->assertNull($received);
    }

    public function testCreatesPromiseWhenFulfilledBeforeThen(): void
    {
        $p = new Promise();
        $p->resolve('foo');
        $carry = null;
        $p2 = $p->then(function (string $v) use (&$carry): void {
            $carry = $v;
        });
        $this->assertNotSame($p, $p2);
        $this->assertNull($carry);
        P\Utils::queue()->run();
        $this->assertSame('foo', $carry);
    }

    public function testResolveWithoutValueBeforeThenNotifiesCallbackWithNull(): void
    {
        $p = new Promise();
        $p->resolve();
        $called = false;
        $received = 'not called';

        $p->then(function ($value) use (&$called, &$received): void {
            $called = true;
            $received = $value;
        });

        P\Utils::queue()->run();

        $this->assertTrue($called);
        $this->assertNull($received);
    }

    public function testCreatesPromiseWhenFulfilledWithNoCallback(): void
    {
        $p = new Promise();
        $p->resolve('foo');
        $p2 = $p->then();
        $this->assertNotSame($p, $p2);
        $this->assertInstanceOf(FulfilledPromise::class, $p2);
    }

    public function testCreatesPromiseWhenRejectedAfterThen(): void
    {
        $p = new Promise();
        $carry = null;
        $p2 = $p->then(null, function (string $v) use (&$carry): void {
            $carry = $v;
        });
        $this->assertNotSame($p, $p2);
        $p->reject('foo');
        P\Utils::queue()->run();
        $this->assertSame('foo', $carry);
    }

    public function testCreatesPromiseWhenRejectedBeforeThen(): void
    {
        $p = new Promise();
        $p->reject('foo');
        $carry = null;
        $p2 = $p->then(null, function (string $v) use (&$carry): void {
            $carry = $v;
        });
        $this->assertNotSame($p, $p2);
        $this->assertNull($carry);
        P\Utils::queue()->run();
        $this->assertSame('foo', $carry);
    }

    public function testForwardsRejectedPromiseWhenResolvedBeforeThen(): void
    {
        $p = new Promise();
        $p->resolve(new RejectedPromise('inner'));

        $result = null;
        $p->then(null, function (string $reason) use (&$result): void {
            $result = $reason;
        });

        P\Utils::queue()->run();
        $this->assertSame('inner', $result);
    }

    public function testCreatesPromiseWhenRejectedWithNoCallback(): void
    {
        $p = new Promise();
        $p->reject('foo');
        $p2 = $p->then();
        $this->assertNotSame($p, $p2);
        $this->assertInstanceOf(RejectedPromise::class, $p2);
    }

    public function testInvokesWaitFnsForThens(): void
    {
        $p = new Promise(function () use (&$p): void {
            $p->resolve('a');
        });
        $p2 = $p
            ->then(function (string $v): string {
                return $v.'-1-';
            })
            ->then(function (string $v): string {
                return $v.'2';
            });
        $this->assertSame('a-1-2', $p2->wait());
    }

    public function testStacksThenWaitFunctions(): void
    {
        $p1 = new Promise(function () use (&$p1): void {
            $p1->resolve('a');
        });
        $p2 = new Promise(function () use (&$p2): void {
            $p2->resolve('b');
        });
        $p3 = new Promise(function () use (&$p3): void {
            $p3->resolve('c');
        });
        $p4 = $p1
            ->then(function () use ($p2): PromiseInterface {
                return $p2;
            })
            ->then(function () use ($p3): PromiseInterface {
                return $p3;
            });
        $this->assertSame('c', $p4->wait());
    }

    public function testForwardsFulfilledDownChainBetweenGaps(): void
    {
        $p = new Promise();
        $r = $r2 = null;
        $p->then(null, null)
            ->then(function (string $v) use (&$r): string {
                $r = $v;

                return $v.'2';
            })
            ->then(function (string $v) use (&$r2): void {
                $r2 = $v;
            });
        $p->resolve('foo');
        P\Utils::queue()->run();
        $this->assertSame('foo', $r);
        $this->assertSame('foo2', $r2);
    }

    public function testForwardsRejectedPromisesDownChainBetweenGaps(): void
    {
        $p = new Promise();
        $r = $r2 = null;
        $p->then(null, null)
            ->then(null, function (string $v) use (&$r): string {
                $r = $v;

                return $v.'2';
            })
            ->then(function (string $v) use (&$r2): void {
                $r2 = $v;
            });
        $p->reject('foo');
        P\Utils::queue()->run();
        $this->assertSame('foo', $r);
        $this->assertSame('foo2', $r2);
    }

    public function testForwardsThrownPromisesDownChainBetweenGaps(): void
    {
        $e = new \Exception();
        $p = new Promise();
        $r = $r2 = null;
        $p->then(null, null)
            ->then(null, function (string $v) use (&$r, $e): void {
                $r = $v;
                throw $e;
            })
            ->then(
                null,
                function (\Exception $v) use (&$r2): void {
                    $r2 = $v;
                }
            );
        $p->reject('foo');
        P\Utils::queue()->run();
        $this->assertSame('foo', $r);
        $this->assertSame($e, $r2);
    }

    public function testForwardsReturnedRejectedPromisesDownChainBetweenGaps(): void
    {
        $p = new Promise();
        $rejected = new RejectedPromise('bar');
        $r = $r2 = null;
        $p->then(null, null)
            ->then(null, function (string $v) use (&$r, $rejected): PromiseInterface {
                $r = $v;

                return $rejected;
            })
            ->then(
                null,
                function (string $v) use (&$r2): void {
                    $r2 = $v;
                }
            );
        $p->reject('foo');
        P\Utils::queue()->run();
        $this->assertSame('foo', $r);
        $this->assertSame('bar', $r2);
        try {
            $p->wait();
        } catch (RejectionException $e) {
            $this->assertSame('foo', $e->getReason());
        }
    }

    public function testForwardsHandlersToNextPromise(): void
    {
        $p = new Promise();
        $p2 = new Promise();
        $resolved = null;
        $p
            ->then(function (string $v) use ($p2): PromiseInterface {
                return $p2;
            })
            ->then(function (string $value) use (&$resolved): void {
                $resolved = $value;
            });
        $p->resolve('a');
        $p2->resolve('b');
        P\Utils::queue()->run();
        $this->assertSame('b', $resolved);
    }

    public function testRemovesReferenceFromChildWhenParentWaitedUpon(): void
    {
        $r = null;
        $p = new Promise(function () use (&$p): void {
            $p->resolve('a');
        });
        $p2 = new Promise(function () use (&$p2): void {
            $p2->resolve('b');
        });
        $pb = $p->then(
            function (string $v) use ($p2, &$r): PromiseInterface {
                $r = $v;

                return $p2;
            }
        )
            ->then(function (string $v): string {
                return $v.'.';
            });
        $this->assertSame('a', $p->wait());
        $this->assertSame('b', $p2->wait());
        $this->assertSame('b.', $pb->wait());
        $this->assertSame('a', $r);
    }

    public function testForwardsHandlersWhenFulfilledPromiseIsReturned(): void
    {
        $res = [];
        $p = new Promise();
        $p2 = new Promise();
        $p2->resolve('foo');
        $p2->then(function (string $v) use (&$res): void {
            $res[] = 'A:'.$v;
        });
        // $res is A:foo
        $p
            ->then(function () use ($p2, &$res): PromiseInterface {
                $res[] = 'B';

                return $p2;
            })
            ->then(function (string $v) use (&$res): void {
                $res[] = 'C:'.$v;
            });
        $p->resolve('a');
        $p->then(function (string $v) use (&$res): void {
            $res[] = 'D:'.$v;
        });
        P\Utils::queue()->run();
        $this->assertSame(['A:foo', 'B', 'D:a', 'C:foo'], $res);
    }

    public function testForwardsHandlersWhenRejectedPromiseIsReturned(): void
    {
        $res = [];
        $p = new Promise();
        $p2 = new Promise();
        $p2->reject('foo');
        $p2->then(null, function (string $v) use (&$res): void {
            $res[] = 'A:'.$v;
        });
        $p->then(null, function () use ($p2, &$res): PromiseInterface {
            $res[] = 'B';

            return $p2;
        })
            ->then(null, function (string $v) use (&$res): void {
                $res[] = 'C:'.$v;
            });
        $p->reject('a');
        $p->then(null, function (string $v) use (&$res): void {
            $res[] = 'D:'.$v;
        });
        P\Utils::queue()->run();
        $this->assertSame(['A:foo', 'B', 'D:a', 'C:foo'], $res);
    }

    public function testDoesNotForwardRejectedPromise(): void
    {
        $res = [];
        $p = new Promise();
        $p2 = new Promise();
        $p2->cancel();
        $p2->then(function (string $v) use (&$res): string {
            $res[] = "B:$v";

            return $v;
        });
        $p->then(function (string $v) use ($p2, &$res): PromiseInterface {
            $res[] = "B:$v";

            return $p2;
        })
            ->then(function (string $v) use (&$res): void {
                $res[] = 'C:'.$v;
            });
        $p->resolve('a');
        $p->then(function (string $v) use (&$res): void {
            $res[] = 'D:'.$v;
        });
        P\Utils::queue()->run();
        $this->assertSame(['B:a', 'D:a'], $res);
    }

    public function testRecursivelyForwardsWhenOnlyThennable(): void
    {
        $res = [];
        $p = new Promise();
        $p2 = new Thennable();
        $p2->resolve('foo');
        $p2->then(function (string $v) use (&$res): void {
            $res[] = 'A:'.$v;
        });
        $p->then(function () use ($p2, &$res): Thennable {
            $res[] = 'B';

            return $p2;
        })
            ->then(function (string $v) use (&$res): void {
                $res[] = 'C:'.$v;
            });
        $p->resolve('a');
        $p->then(function (string $v) use (&$res): void {
            $res[] = 'D:'.$v;
        });
        P\Utils::queue()->run();
        $this->assertSame(['A:foo', 'B', 'D:a', 'C:foo'], $res);
    }

    public function testRecursivelyForwardsWhenNotInstanceOfPromise(): void
    {
        $res = [];
        $p = new Promise();
        $p2 = new NotPromiseInstance();
        $p2->then(function (string $v) use (&$res): void {
            $res[] = 'A:'.$v;
        });
        $p->then(function () use ($p2, &$res): PromiseInterface {
            $res[] = 'B';

            return $p2;
        })
            ->then(function (string $v) use (&$res): void {
                $res[] = 'C:'.$v;
            });
        $p->resolve('a');
        $p->then(function (string $v) use (&$res): void {
            $res[] = 'D:'.$v;
        });
        P\Utils::queue()->run();
        $this->assertSame(['B', 'D:a'], $res);
        $p2->resolve('foo');
        P\Utils::queue()->run();
        $this->assertSame(['B', 'D:a', 'A:foo', 'C:foo'], $res);
    }

    public function testCannotResolveWithSelf(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot fulfill or reject a promise with itself');

        $p = new Promise();
        $p->resolve($p);
    }

    public function testCannotRejectWithSelf(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot fulfill or reject a promise with itself');

        $p = new Promise();
        $p->reject($p);
    }

    public function testCannotResolveWithPromiseThatDependsOnIt(): void
    {
        $p = new Promise();
        $p2 = new Promise();
        $p->resolve($p2);

        try {
            $p2->resolve($p);
            $this->fail('Expected LogicException');
        } catch (\LogicException $e) {
            $this->assertSame('Cannot fulfill or reject a promise with a promise that depends on it', $e->getMessage());
        }

        $this->assertTrue(P\Is::pending($p2));
        $p2->resolve('foo');
        $this->assertSame('foo', $p->wait());
    }

    public function testCannotRejectWithPromiseThatDependsOnIt(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot fulfill or reject a promise with a promise that depends on it');

        $p = new Promise();
        $p2 = new Promise();
        $p->resolve($p2);
        $p2->reject($p);
    }

    public function testDoesNotBlowStackWhenWaitingOnNestedThens(): void
    {
        $inner = new Promise(function () use (&$inner): void {
            $inner->resolve(0);
        });
        $prev = $inner;
        for ($i = 1; $i < 100; ++$i) {
            $prev = $prev->then(function (int $i): int {
                return $i + 1;
            });
        }

        $parent = new Promise(function () use (&$parent, $prev): void {
            $parent->resolve($prev);
        });

        $this->assertSame(99, $parent->wait());
    }

    public function testOtherwiseIsSugarForRejections(): void
    {
        $p = new Promise();
        $p->reject('foo');
        $p->otherwise(function (string $v) use (&$c): void {
            $c = $v;
        });
        P\Utils::queue()->run();
        $this->assertSame($c, 'foo');
    }

    public function testRepeatedWaitFulfilled(): void
    {
        $promise = new Promise(function () use (&$promise): void {
            $promise->resolve('foo');
        });

        $this->assertSame('foo', $promise->wait());
        $this->assertSame('foo', $promise->wait());
    }

    public function testRepeatedWaitRejected(): void
    {
        $promise = new Promise(function () use (&$promise): void {
            $promise->reject(new \RuntimeException('foo'));
        });

        $exceptionCount = 0;
        try {
            $promise->wait();
        } catch (\Exception $e) {
            $this->assertSame('foo', $e->getMessage());
            ++$exceptionCount;
        }

        try {
            $promise->wait();
        } catch (\Exception $e) {
            $this->assertSame('foo', $e->getMessage());
            ++$exceptionCount;
        }

        $this->assertSame(2, $exceptionCount);
    }

    /**
     * @dataProvider foreignCallProvider
     */
    public function testForeignCoroutineCannotUsePendingPromise(string $method, array $args): void
    {
        $calls = [];
        $owner = $foreign = $before = $after = $exception = null;

        self::runInCoroutine(function () use ($method, $args, &$calls, &$owner, &$foreign, &$before, &$after, &$exception): void {
            $owner = \Swoole\Coroutine::getCid();
            $p = new Promise(
                function () use (&$calls): void {
                    $calls[] = 'wait';
                },
                function () use (&$calls): void {
                    $calls[] = 'cancel';
                }
            );
            $p->then(function (string $v) use (&$calls): void {
                $calls[] = 'then:'.$v;
            });
            $before = self::promiseState($p);

            \Swoole\Coroutine::create(function () use ($p, $method, $args, &$foreign, &$exception): void {
                $foreign = \Swoole\Coroutine::getCid();
                try {
                    $p->{$method}(...$args);
                } catch (\LogicException $e) {
                    $exception = $e;
                }
            });

            $after = self::promiseState($p);
            $p->resolve('owner');
            P\Utils::queue()->run();
        });

        $this->assertInstanceOf(\LogicException::class, $exception);
        $this->assertSame(
            "Coroutine {$foreign} cannot call {$method}() on an unfinished promise owned by coroutine {$owner}. Create and finish the operation in the same coroutine, then share its settled result.",
            $exception->getMessage()
        );
        $this->assertSame($before, $after);
        $this->assertSame(['then:owner'], $calls);
    }

    public static function foreignCallProvider(): array
    {
        return [
            ['then', [function (): void {}]],
            ['wait', []],
            ['cancel', []],
            ['resolve', ['foreign']],
            ['reject', ['foreign']],
        ];
    }

    public function testSettledPromiseCanBeUsedInOtherCoroutines(): void
    {
        $results = [];

        self::runInCoroutine(function () use (&$results): void {
            $fulfilled = new Promise();
            $fulfilled->resolve('foo');
            $rejected = new Promise();
            $rejected->reject('bar');

            \Swoole\Coroutine::create(function () use ($fulfilled, $rejected, &$results): void {
                $results[] = $fulfilled->then(function (string $v): string {
                    return $v.'!';
                })->wait();
                $results[] = $rejected->otherwise(function (string $v): string {
                    return $v.'?';
                })->wait();
                $results[] = $fulfilled->wait();
            });
        });

        $this->assertSame(['foo!', 'bar?', 'foo'], $results);
    }

    public function testForeignCoroutineKeepsSettledPromiseRules(): void
    {
        $results = [];

        self::runInCoroutine(function () use (&$results): void {
            $p = new Promise();
            $p->resolve('foo');

            \Swoole\Coroutine::create(function () use ($p, &$results): void {
                $p->resolve('foo');
                $p->cancel();
                foreach (['resolve', 'reject'] as $method) {
                    try {
                        $p->{$method}('bar');
                    } catch (\LogicException $e) {
                        $results[] = $e->getMessage();
                    }
                }
            });
            $results[] = $p->wait();
        });

        $this->assertSame([
            'The promise is already fulfilled.',
            'Cannot change a fulfilled promise to rejected',
            'foo',
        ], $results);
    }

    public function testPromiseResolvedWithPendingPromiseStaysOwnedUntilItSettles(): void
    {
        $exception = $result = null;

        self::runInCoroutine(function () use (&$exception, &$result): void {
            $inner = new Promise();
            $middle = new Promise();
            $outer = new Promise();
            $middle->resolve($inner);
            $outer->resolve($middle);

            \Swoole\Coroutine::create(function () use ($outer, &$exception): void {
                try {
                    $outer->then();
                } catch (\LogicException $e) {
                    $exception = $e;
                }
            });

            $inner->resolve('foo');

            \Swoole\Coroutine::create(function () use ($outer, &$result): void {
                $result = $outer->wait();
            });
        });

        $this->assertInstanceOf(\LogicException::class, $exception);
        $this->assertStringContainsString('cannot call then() on an unfinished promise', $exception->getMessage());
        $this->assertSame('foo', $result);
    }

    public function testCannotSettleWithPendingPromiseOwnedByAnotherCoroutine(): void
    {
        $messages = [];
        $pendingOwner = $caller = $pendingHandlers = $stillPending = $result = null;

        self::runInCoroutine(function () use (&$messages, &$pendingOwner, &$caller, &$pendingHandlers, &$stillPending, &$result): void {
            $pendingOwner = \Swoole\Coroutine::getCid();
            $pending = new Promise();

            \Swoole\Coroutine::create(function () use ($pending, &$messages, &$caller, &$pendingHandlers, &$stillPending, &$result): void {
                $caller = \Swoole\Coroutine::getCid();
                $p = new Promise();
                $chained = $p->then(function (string $v): string {
                    return $v.'!';
                });

                foreach (['resolve', 'reject'] as $method) {
                    try {
                        $p->{$method}($pending);
                    } catch (\LogicException $e) {
                        $messages[] = $e->getMessage();
                    }
                }

                $pendingHandlers = PropertyHelper::get($pending, 'handlers');
                $stillPending = P\Is::pending($p);
                $p->resolve('foo');
                $result = $chained->wait();
            });

            $pending->resolve('done');
        });

        $message = "Coroutine {$caller} cannot settle a promise with an unfinished promise owned by coroutine {$pendingOwner}. Create and finish the operation in the same coroutine, then share its settled result.";
        $this->assertSame([$message, $message], $messages);
        $this->assertSame([], $pendingHandlers);
        $this->assertTrue($stillPending);
        $this->assertSame('foo!', $result);
    }

    public function testCannotResolveWithPendingCoroutineOwnedByAnotherCoroutine(): void
    {
        $exception = $stillPending = $result = null;

        self::runInCoroutine(function () use (&$exception, &$stillPending, &$result): void {
            $pending = new Promise();
            $coroutine = Coroutine::of(function () use ($pending): \Generator {
                yield $pending;
            });

            \Swoole\Coroutine::create(function () use ($coroutine, &$exception, &$stillPending, &$result): void {
                $p = new Promise();
                $chained = $p->then(function (string $v): string {
                    return $v.'!';
                });

                try {
                    $p->resolve($coroutine);
                } catch (\LogicException $e) {
                    $exception = $e;
                }

                $stillPending = P\Is::pending($p);
                $p->resolve('foo');
                $result = $chained->wait();
            });

            $pending->resolve('done');
            P\Utils::queue()->run();
        });

        $this->assertInstanceOf(\LogicException::class, $exception);
        $this->assertStringContainsString('cannot settle a promise with an unfinished promise owned by coroutine', $exception->getMessage());
        $this->assertTrue($stillPending);
        $this->assertSame('foo!', $result);
    }

    public function testCannotSettleWithPromiseOwnedByAnotherCoroutineThatWaitsOnThirdPartyPromise(): void
    {
        $messages = $usable = [];
        $result = null;

        self::runInCoroutine(function () use (&$messages, &$usable, &$result): void {
            $thirdParty = new NotPromiseInstance();
            $wrapper = new Promise();
            $wrapper->resolve($thirdParty);

            \Swoole\Coroutine::create(function () use ($wrapper, &$messages, &$usable): void {
                foreach (['resolve', 'reject'] as $method) {
                    foreach ([true, false] as $withHandler) {
                        $p = new Promise();
                        $chained = $withHandler ? $p->then() : $p;

                        try {
                            $p->{$method}($wrapper);
                        } catch (\LogicException $e) {
                            $messages[] = $e->getMessage();
                        }

                        $p->resolve($method);
                        $usable[] = $chained->wait();
                    }
                }
            });

            $thirdParty->resolve('foo');

            \Swoole\Coroutine::create(function () use ($wrapper, &$result): void {
                $p = new Promise();
                $chained = $p->then(function (string $v): string {
                    return $v.'!';
                });
                $p->resolve($wrapper);
                $result = $chained->wait();
            });
        });

        $this->assertCount(4, $messages);
        foreach ($messages as $message) {
            $this->assertStringContainsString('cannot settle a promise with an unfinished promise owned by coroutine', $message);
        }
        $this->assertSame(['resolve', 'resolve', 'reject', 'reject'], $usable);
        $this->assertSame('foo!', $result);
    }

    public function testPendingThirdPartyPromiseKeepsAdoptingPromiseOwned(): void
    {
        $refused = [];
        $state = $result = null;

        self::runInCoroutine(function () use (&$refused, &$state, &$result): void {
            $p = new Promise();
            $thirdParty = new NotPromiseInstance();
            $p->resolve($thirdParty);
            $state = $p->getState();

            \Swoole\Coroutine::create(function () use ($p, &$refused): void {
                foreach (['then', 'wait'] as $method) {
                    try {
                        $p->{$method}();
                    } catch (\LogicException $e) {
                        $refused[] = $method;
                    }
                }
            });

            $thirdParty->resolve('foo');

            \Swoole\Coroutine::create(function () use ($p, &$result): void {
                $result = $p->then(function (string $v): string {
                    return $v.'!';
                })->wait();
            });
        });

        $this->assertSame(PromiseInterface::FULFILLED, $state);
        $this->assertSame(['then', 'wait'], $refused);
        $this->assertSame('foo!', $result);
    }

    public function testPendingPromiseCreatedOutsideCoroutinesCannotBeSettledInside(): void
    {
        $p = new Promise();
        $exception = null;

        self::runInCoroutine(function () use ($p, &$exception): void {
            try {
                $p->resolve('foo');
            } catch (\LogicException $e) {
                $exception = $e;
            }
        });

        $this->assertInstanceOf(\LogicException::class, $exception);
        $this->assertStringContainsString('cannot call resolve() on an unfinished promise owned by coroutine -1', $exception->getMessage());
        $this->assertTrue(P\Is::pending($p));
        $p->resolve('bar');
        $this->assertSame('bar', $p->wait());
    }

    /**
     * Runs the callback in a coroutine scheduler with its own task queue. A
     * coroutine that drains the shared queue would also run tasks left by
     * other tests, which settle promises owned outside coroutines.
     */
    private static function runInCoroutine(callable $callback): void
    {
        $queue = P\Utils::queue();
        P\Utils::queue(new TaskQueue(false));

        try {
            \Swoole\Coroutine\run($callback);
        } finally {
            P\Utils::queue($queue);
        }
    }

    private static function promiseState(Promise $p): array
    {
        $state = [];
        foreach (['state', 'result', 'handlers', 'waitFn', 'cancelFn', 'waitList'] as $property) {
            $state[$property] = PropertyHelper::get($p, $property);
        }

        return $state;
    }
}
