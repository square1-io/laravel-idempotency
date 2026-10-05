![Build and Test](https://github.com/square1-io/laravel-idempotency/actions/workflows/laravel.yml/badge.svg)

# Laravel Idempotency

Laravel Idempotency helps to prevent duplicate API operations. It uses a client key to identify each operation.

The first request calls the route. For an identical duplicate, the package can return the stored response without another route call.

Use this package for operations such as payments, orders, and form submissions.

## Installation

Use Composer to install the package:

```bash
composer require square1/laravel-idempotency
```

Laravel registers the service provider.

If you must change the default configuration, publish the configuration file:

```bash
php artisan vendor:publish --tag=idempotency-config
```

## Use the middleware

Add the `idempotency` middleware alias to routes that create or change data:

```php
use Illuminate\Support\Facades\Route;

Route::post('/payments', ProcessPaymentController::class)
    ->middleware('idempotency');
```

Send one stable key for each operation:

```http
Idempotency-Key: 8f7de09d-1738-43f1-a710-13f9957e9d5f
```

The first request calls the route. For an identical duplicate, the package returns the stored status, body, and permitted headers.

The package adds the replayed key to the response header:

```http
Idempotency-Replayed: 8f7de09d-1738-43f1-a710-13f9957e9d5f
```

By default, the middleware examines `POST`, `PUT`, `PATCH`, and `DELETE` requests. It does not examine other request methods.

For user scope, put the authentication middleware before the idempotency middleware.

## Configuration

| Option | Default | Purpose |
|---|---:|---|
| `cache_duration` | `86400` | Cache time in seconds. |
| `cache_store` | `null` | Laravel cache store. A null value selects the application default. |
| `idempotency_header` | `Idempotency-Key` | Request header that contains the client key. |
| `ignore_empty_key` | `false` | Permit a protected request with no key. |
| `enforced_verbs` | POST, PUT, PATCH, DELETE | Request methods that the middleware examines. |
| `scope` | `user` | Key scope. Use `user`, `ip`, or `global`. |
| `on_duplicate_behaviour` | `replay` | Action for a completed duplicate. Use `replay` or `exception`. |
| `on_in_flight_behaviour` | `reject` | Action for an in-flight duplicate. Use `reject` or `wait`. |
| `lock_ttl` | `30` | Lock lease in seconds. |
| `wait_timeout` | `2` | Maximum wait time in seconds. |
| `cache_statuses` | All categories enabled | HTTP response categories that the package stores. |
| `user_id_resolver` | `null` | Class and method that supply the user identifier. |

The package checks the configuration before it handles a protected request. Invalid configuration causes an `InvalidConfigurationException`.

## In-flight duplicates

An in-flight duplicate arrives while the first request owns the cache lock.

By default, the package rejects the duplicate:

```http
HTTP/1.1 409 Conflict
Retry-After: 1
```

The `Retry-After` value is one second.

To wait briefly for the original response, use:

```php
'on_in_flight_behaviour' => 'wait',
'wait_timeout' => 2,
```

Each duplicate uses one application worker during the wait time.

The `wait_timeout` option does not change the lock lease. Set `lock_ttl` above the longest expected request time.

If the lock lease ends before the first request ends, a duplicate can get the lock.

### Route override

Use the middleware parameter to override the configured behavior on one route:

```php
Route::post('/internal-operation', InternalController::class)
    ->middleware('idempotency:wait');

Route::post('/payments', PaymentController::class)
    ->middleware('idempotency:reject');
```

Use only `wait` or `reject`. The package rejects a different value with an `InvalidConfigurationException`.

## Completed duplicates

The `on_duplicate_behaviour` option controls the action for a completed duplicate.

With `replay`, the package returns the stored response. With `exception`, it throws a `DuplicateRequestException` with status `409`.

## Request comparison

The package stores a request fingerprint with each new response. It compares a duplicate request with this fingerprint.

The fingerprint contains these request values:

- HTTP method
- Host
- Path
- Query parameters
- Request body
- Content type
- Uploaded file names, paths, media types, sizes, and content hashes

If a duplicate has a different fingerprint, the package throws a `MismatchedRequestException` with status `422`.

The package sorts JSON object keys and query parameters before it creates the fingerprint. The package does not sort array values.

The package hashes the client key and scope to make a fixed-length cache key. The replay header contains the original key so clients can correlate the response with the request they sent. Reusing a key with different request data returns `422` instead of a replay.

An idempotency key must contain at least one non-whitespace character. It can contain a maximum of 255 bytes.

An idempotency key must not contain control characters.

If a protected request has no key, the package throws a `MissingIdempotencyKeyException` with status `400`.

If `ignore_empty_key` is `true`, the package calls the route without a lock or cache entry.

## Scopes

The package supports these scopes:

| Scope | Behavior |
|---|---|
| `user` | The package separates keys by authenticated user. It uses the request IP address for a guest. |
| `ip` | The package separates keys by request IP address. |
| `global` | The package shares a key across all users and IP addresses. |

For a trusted webhook event ID, you can use global scope:

```php
'scope' => 'global',
```

By default, user scope calls `getAuthIdentifier()` on the authenticated user.

To supply a different identifier, configure a class and method:

```php
'user_id_resolver' => [App\Support\ResolveIdempotencyUser::class, 'resolve'],
```

```php
namespace App\Support;

use Illuminate\Http\Request;

class ResolveIdempotencyUser
{
    public function resolve(Request $request): string
    {
        return (string) $request->user()->account_id;
    }
}
```

The resolver must return a non-empty scalar value. The resolver can have a `Request` parameter.

The package can also call a resolver from an earlier package version that has no `Request` parameter.

## Response cache

The package assigns each response to one category:

| Category | HTTP status range |
|---|---:|
| `informational` | 100 through 199 |
| `success` | 200 through 299 |
| `redirection` | 300 through 399 |
| `client_error` | 400 through 499 |
| `server_error` | 500 through 599 |

By default, the package stores all categories.

If the route must run again after a response category, disable that category:

```php
'cache_statuses' => [
    'informational' => true,
    'success' => true,
    'redirection' => true,
    'client_error' => false,
    'server_error' => true,
],
```

An application can complete a side effect before it returns an error. A retry can repeat the side effect if the package does not store the error.

The package excludes connection specific headers, `Date`, and `Set-Cookie` from stored responses.

The package cannot replay a `StreamedResponse` or a `BinaryFileResponse`. It stores a completion marker instead of the response body.

For an identical duplicate, the package throws an `UnreplayableResponseException` with status `409`. It does not call the route again.

If you disable the applicable response category, the package does not store a completion marker.

## Cache requirements

The selected cache store must support Laravel atomic locks. All application servers must use the same shared cache store.

Use the array cache store only when one process handles all protected requests.

Use the file cache store only when one host handles all protected requests.

The package uses a `finally` block to release the lock that it owns. It also releases the lock when downstream code throws an exception.

The cache store expires the lock after the `lock_ttl` time.

## Request attributes

Before the package calls a route for a new operation, it adds these request attributes:

| Attribute | Value |
|---|---|
| `idempotent` | `true` |
| `idempotency-key` | Client key from the request header. |
| `idempotency-scope` | Resolved scope and identifier, such as `user:42`, `ip:192.0.2.1`, or `global:`. |

The package does not add these attributes when it replays a response. It does not add them when it bypasses a request.

## Exceptions

All package exceptions extend `IdempotencyException`. This base class extends the Symfony `HttpException` class.

| Exception | HTTP status |
|---|---:|
| `MissingIdempotencyKeyException` | 400 |
| `InvalidIdempotencyKeyException` | 400 |
| `RequestInProgressException` | 409 |
| `DuplicateRequestException` | 409 |
| `UnreplayableResponseException` | 409 |
| `MismatchedRequestException` | 422 |
| `InvalidConfigurationException` | 500 |
| `CorruptedCacheDataException` | 500 |
| `InvalidCachedValueException` | 500 |

Your application can catch a concrete exception or `IdempotencyException`. Laravel uses the exception status and headers for the HTTP response.

## Upgrade from v13

If you published `config/idempotency.php`, edit that file when upgrading. Without a cached configuration, Laravel merges in new top-level defaults, but the old `max_lock_wait_time` entry has no effect in v14. Add the new options you want to customize, and rebuild your application's configuration cache during deployment. A stale configuration cache may lack required v14 options.

| v13 behavior or setting | v14 action |
|---|---|
| Concurrent requests waited for a cached response using `max_lock_wait_time`. | Set `on_in_flight_behaviour` to `wait` and choose a `wait_timeout` in seconds if you want to keep waiting. The default now rejects immediately with `409` and `Retry-After: 1`. Set `lock_ttl` longer than the longest expected request; it controls the lock lease, not the wait. |
| A replay sent `Idempotency-Relayed: <key>`. | Read the same key from `Idempotency-Replayed` instead. |
| A reused key was compared only by request path. | The method, host, path, query, body, content type, and uploads must now match. A mismatch returns `422`; update clients that reuse a key for changed request data. |
| `LockWaitExceededException` and `MismatchedPathException` could be caught by application code. | Catch `RequestInProgressException` and `MismatchedRequestException`, respectively. Package exceptions now carry HTTP status codes. |
| Missing keys and completed duplicates raised ordinary exceptions. | They now return `400` and `409`, respectively. Update clients or exception handlers that relied on the old error response. Keys containing only whitespace, control characters, or more than 255 bytes now return `400`. |
| Response headers, including `Set-Cookie`, were stored for replay. | Replays omit `Set-Cookie`, `Date`, and connection specific headers. Put any required cookie handling outside the replayed response. |
| A streamed or file response could not be replayed reliably. | A completed duplicate now returns `409` without running the route again. Use a regular response if the client must receive a replay. |

Version 14 uses new cache keys and lock names. It does not read v13 entries or coordinate with v13 locks, so a key used under v13 can execute again under v14. Drain active requests before switching versions. For an operation that cannot be repeated, stop its traffic until the last v13 cache entry has expired (`cache_duration` after the last v13 request), then resume on v14. Rolling deployments that run v13 and v14 nodes together cannot provide cross-version idempotency.

Version 14 and later use semantic version numbers independently from Laravel versions. See the [changelog](CHANGELOG.md) for the release summary.

## Version support

| Package version | Laravel versions | PHP constraint |
|---|---|---|
| 14.x | 12 and 13 | `^8.2` |
| 13.x | 12 and 13 | `^8.2` |
| 12.x | 10, 11, and 12 | `^8.2` |
| 2.0 | 9, 10, and 11 | `^8.2` |
| 1.x | 9 and 10 | `^8.1` |

A new Laravel version does not require a new package major version when the package remains backward-compatible.

## Tests

Run the tests and PHPStan:

```bash
composer test
```

Run the format check:

```bash
vendor/bin/pint --test
```

To run the Redis integration tests, start Redis on `127.0.0.1:6379`.

Then, run this command:

```bash
IDEMPOTENCY_REDIS_TESTS=1 vendor/bin/phpunit tests/Integration
```

Without `IDEMPOTENCY_REDIS_TESTS=1`, PHPUnit skips the Redis integration tests.

## Changelog

Refer to [CHANGELOG.md](CHANGELOG.md) for release information.

## Further reading

- [Avoiding Déjà Vu: Building Resilient APIs with Idempotency](https://www.conroyp.com/articles/avoiding-deja-vu-building-resilient-apis-with-idempotency) by Paul Conroy explains the design choices and failure cases behind idempotent APIs.
- [Designing robust and predictable APIs with idempotency](https://stripe.com/blog/idempotency) by Stripe.
- [Making retries safe with idempotent APIs](https://aws.amazon.com/builders-library/making-retries-safe-with-idempotent-APIs/) by AWS.

## License

Laravel Idempotency uses the [MIT license](LICENSE.md).
