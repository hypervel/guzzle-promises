# Guzzle Promises

This is Hypervel's fork of Guzzle Promises for Swoole coroutines. It replaces
`guzzlehttp/promises` and adds coroutine ownership checks. See
[Hypervel Fork](#hypervel-fork).

`guzzlehttp/promises` is a small promise library used by Guzzle for asynchronous
operations. It implements promise chaining, synchronous waiting, cancellation,
and helpers for working with groups of promises.

Most application developers use this package through
[`guzzlehttp/guzzle`](https://github.com/guzzle/guzzle/blob/8.0/README.md) by
calling methods such as `requestAsync()`. Install this package directly when you
need promise composition without the full HTTP client.

## Hypervel Fork

[Hypervel](https://hypervel.org) runs many Swoole coroutines in one long-lived
PHP process, and a promise that one coroutine is still working on must not be
waited on, chained onto, or settled by another. This fork adds:

- **Coroutine ownership.** A pending `Promise` belongs to the coroutine that
  created it, and calls from other coroutines throw a `LogicException` without
  changing the promise. Settled results can be shared freely. See
  [Coroutine Ownership](docs/promise-api.md#coroutine-ownership).
- **Scoped task queues.** `ScopedTaskQueueInterface` lets a task queue that runs
  per coroutine track pending work, such as HTTP transfers, and cancel it when
  the coroutine ends. See [Task Queue](docs/promise-api.md#task-queue).

> [!IMPORTANT]
> This fork keeps upstream's public API. Once installed, it replaces
> `guzzlehttp/promises` for packages with compatible version requirements,
> including third-party packages and SDKs, so their existing code keeps working
> unchanged. Changes to the fork must stay backward compatible.

## Installation

```bash
composer require hypervel/guzzle-promises
```

## Version Guidance

This fork requires PHP 8.4 or newer and the Swoole extension.
[composer.json](composer.json) lists the exact requirements. Each fork version
is the upstream release it is based on plus a fork release number. For example,
`3.0.2.1` is the first fork release based on upstream `3.0.2`.

## Quick Start

```php
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\Utils;

$promise = new Promise();

$promise->then(
    function ($value) {
        echo 'Fulfilled: ' . $value;
    },
    function ($reason) {
        echo 'Rejected: ' . $reason;
    }
);

$promise->resolve('done');
Utils::queue()->run();
```

You can wait for a promise to complete synchronously:

```php
$value = $promise->wait();
```

A promise's `getState()` describes how it was settled, not the eventual
outcome: a promise that was resolved with another promise, directly or by
returning one from a `then()` handler, reports `fulfilled` while the inner
promise may still be pending, and `wait()` can still throw if the inner
promise rejects. Poll the state only on promises settled with plain values,
or call `wait()` first (see
[guzzle/promises#101](https://github.com/guzzle/promises/issues/101)).

When using Guzzle HTTP requests, asynchronous methods return
`GuzzleHttp\Promise\PromiseInterface` instances:

```php
$promise = $client->requestAsync('GET', 'https://example.com');
$response = $promise->wait();
```

## Documentation

- [Promise Quick Start](docs/promise-quick-start.md)
- [Promise API](docs/promise-api.md)
- [Promise Interoperability](docs/promise-interoperability.md)
- [Implementation Notes](docs/implementation-notes.md)
- [Upgrade Guide](UPGRADING.md)
- [Changelog](CHANGELOG.md)

## Security

If you discover a security vulnerability within this package, please send an
email to security@tidelift.com. All security vulnerabilities will be promptly
addressed. Please do not disclose security-related issues publicly until a fix
has been announced. Please see
[Security Policy](https://github.com/guzzle/promises/security/policy) for more
information.

## License

Guzzle is made available under the MIT License (MIT). Please see
[License File](LICENSE) for more information.

## For Enterprise

Available as part of the Tidelift Subscription

The maintainers of Guzzle and thousands of other packages are working with
Tidelift to deliver commercial support and maintenance for the open source
dependencies you use to build your applications. Save time, reduce risk, and
improve code health, while paying the maintainers of the exact dependencies you
use.
[Learn more.](https://tidelift.com/subscription/pkg/packagist-guzzlehttp-promises?utm_source=packagist-guzzlehttp-promises&utm_medium=referral&utm_campaign=enterprise&utm_term=repo)
