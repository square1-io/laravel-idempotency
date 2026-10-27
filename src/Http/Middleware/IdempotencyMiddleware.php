<?php

namespace Square1\LaravelIdempotency\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Square1\LaravelIdempotency\Enums\DuplicateBehaviour;
use Square1\LaravelIdempotency\Enums\InFlightBehaviour;
use Square1\LaravelIdempotency\Enums\ResponseCategory;
use Square1\LaravelIdempotency\Exceptions\CorruptedCacheDataException;
use Square1\LaravelIdempotency\Exceptions\DuplicateRequestException;
use Square1\LaravelIdempotency\Exceptions\InvalidConfigurationException;
use Square1\LaravelIdempotency\Exceptions\InvalidIdempotencyKeyException;
use Square1\LaravelIdempotency\Exceptions\MismatchedRequestException;
use Square1\LaravelIdempotency\Exceptions\MissingIdempotencyKeyException;
use Square1\LaravelIdempotency\Exceptions\RequestInProgressException;
use Square1\LaravelIdempotency\Exceptions\UnreplayableResponseException;
use Square1\LaravelIdempotency\Providers\CachedResponseValue;
use Square1\LaravelIdempotency\Support\CacheKeys;
use Square1\LaravelIdempotency\Support\IdempotencyOptions;
use Square1\LaravelIdempotency\Support\RequestFingerprint;
use Square1\LaravelIdempotency\Support\ScopeResolver;
use Symfony\Component\HttpFoundation\Response;

class IdempotencyMiddleware
{
    private const REPLAY_HEADER = 'Idempotency-Replayed';

    private const MAX_KEY_LENGTH = 255;

    private const POLL_INTERVAL_MILLISECONDS = 100;

    private const EXCLUDED_RESPONSE_HEADERS = [
        'connection',
        'date',
        'keep-alive',
        'proxy-authenticate',
        'proxy-authorization',
        'set-cookie',
        'te',
        'trailer',
        'transfer-encoding',
        'upgrade',
    ];

    public function __construct(
        private readonly ScopeResolver $scopeResolver,
        private readonly RequestFingerprint $fingerprint,
        private readonly CacheKeys $cacheKeys,
    ) {}

    public function handle(Request $request, Closure $next, ?string $inFlightBehaviour = null): Response
    {
        $options = IdempotencyOptions::fromConfig($inFlightBehaviour);

        if (! in_array(strtoupper($request->method()), $options->enforcedVerbs, true)) {
            return $next($request);
        }

        $idempotencyKey = $this->resolveIdempotencyKey($request, $options);
        if ($idempotencyKey === null) {
            return $next($request);
        }

        $scope = $this->scopeResolver->resolve($request, $options);
        $cache = Cache::store($options->cacheStore);
        $cacheKey = $this->buildCacheKey($scope->cacheValue(), $idempotencyKey);
        $cached = $this->getCachedResponse($cache, $cacheKey);
        if ($cached instanceof CachedResponseValue) {
            return $this->handleCachedResponse($cached, $request, $idempotencyKey, $options);
        }

        $store = $cache->getStore();
        if (! $store instanceof LockProvider) {
            throw new InvalidConfigurationException('The configured idempotency cache store does not support atomic locks.');
        }

        $lock = $store->lock($this->buildLockKey($cacheKey), $options->lockTtl);

        if (! $lock->get()) {
            if ($options->inFlightBehaviour === InFlightBehaviour::REJECT) {
                throw new RequestInProgressException(
                    __('A request with this idempotency key is already being processed.'),
                );
            }

            return $this->waitForResponse(
                $cache,
                $cacheKey,
                $request,
                $idempotencyKey,
                $options,
            );
        }

        try {
            $cached = $this->getCachedResponse($cache, $cacheKey);
            if ($cached instanceof CachedResponseValue) {
                return $this->handleCachedResponse($cached, $request, $idempotencyKey, $options);
            }

            $request->attributes->set('idempotent', true);
            $request->attributes->set('idempotency-key', $idempotencyKey);
            $request->attributes->set('idempotency-scope', $scope->cacheValue());

            return $this->processRequest(
                $request,
                $cache,
                $cacheKey,
                $idempotencyKey,
                $next,
                $options,
            );
        } finally {
            $lock->release();
        }
    }

    protected function buildCacheKey(string $scope, string $idempotencyKey): string
    {
        return $this->cacheKeys->response($scope, $idempotencyKey);
    }

    protected function buildLockKey(string $cacheKey): string
    {
        return $this->cacheKeys->lock($cacheKey);
    }

    protected function processRequest(
        Request $request,
        Repository $cache,
        string $cacheKey,
        string $idempotencyKey,
        Closure $next,
        IdempotencyOptions $options,
    ): Response {
        $fingerprint = $this->fingerprint->make($request);
        $response = $next($request);
        $category = ResponseCategory::fromStatusCode($response->getStatusCode());

        if (! $options->cacheStatuses[$category->value]) {
            return $response;
        }

        $content = $response->getContent();
        $replayable = is_string($content);
        $cached = [
            'version' => 2,
            'body' => $replayable ? $content : null,
            'status' => $response->getStatusCode(),
            'headers' => $this->filterHeaders($response->headers->all()),
            'path' => $request->path(),
            'originalKey' => $idempotencyKey,
            'fingerprint' => $fingerprint,
            'replayable' => $replayable,
        ];

        $cache->put($cacheKey, $cached, $options->cacheDuration);

        return $response;
    }

    private function resolveIdempotencyKey(Request $request, IdempotencyOptions $options): ?string
    {
        $idempotencyKey = $request->header($options->header);

        if ($idempotencyKey === null || $idempotencyKey === '') {
            if ($options->ignoreEmptyKey) {
                return null;
            }

            throw new MissingIdempotencyKeyException(__("Idempotency key \"{$options->header}\" not found."));
        }

        if (! is_string($idempotencyKey)) {
            throw new InvalidIdempotencyKeyException(__('The idempotency key must be a string.'));
        }

        if (trim($idempotencyKey) === '') {
            throw new InvalidIdempotencyKeyException(__('The idempotency key cannot contain only whitespace.'));
        }

        if (strlen($idempotencyKey) > self::MAX_KEY_LENGTH) {
            throw new InvalidIdempotencyKeyException(__(
                'The idempotency key cannot exceed '.self::MAX_KEY_LENGTH.' bytes.',
            ));
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $idempotencyKey) === 1) {
            throw new InvalidIdempotencyKeyException(__('The idempotency key contains invalid control characters.'));
        }

        return $idempotencyKey;
    }

    private function waitForResponse(
        Repository $cache,
        string $cacheKey,
        Request $request,
        string $idempotencyKey,
        IdempotencyOptions $options,
    ): Response {
        $deadline = hrtime(true) + ($options->waitTimeout * 1_000_000_000);

        do {
            $cached = $this->getCachedResponse($cache, $cacheKey);
            if ($cached instanceof CachedResponseValue) {
                return $this->handleCachedResponse($cached, $request, $idempotencyKey, $options);
            }

            $remainingMicroseconds = intdiv(max(0, $deadline - hrtime(true)), 1_000);
            if ($remainingMicroseconds === 0) {
                break;
            }

            $baseMicroseconds = min(self::POLL_INTERVAL_MILLISECONDS * 1_000, $remainingMicroseconds);
            $jitterMicroseconds = random_int(0, max(1, intdiv($baseMicroseconds, 4)));
            usleep((int) min($baseMicroseconds + $jitterMicroseconds, $remainingMicroseconds));
        } while (hrtime(true) < $deadline);

        throw new RequestInProgressException(
            __("A response was not available after waiting {$options->waitTimeout} seconds."),
        );
    }

    private function getCachedResponse(
        Repository $cache,
        string $cacheKey,
    ): ?CachedResponseValue {
        $cached = $cache->get($cacheKey);

        if ($cached === null) {
            return null;
        }

        return $this->hydrateCachedResponse($cached);
    }

    private function hydrateCachedResponse(mixed $cached): CachedResponseValue
    {
        if (! is_array($cached)) {
            throw new CorruptedCacheDataException(__('Unexpected cache payload found. Expected an array.'));
        }

        $requiredKeys = ['version', 'body', 'status', 'headers', 'path', 'originalKey', 'fingerprint', 'replayable'];
        foreach ($requiredKeys as $key) {
            if (! array_key_exists($key, $cached)) {
                throw new CorruptedCacheDataException(__("Cached array is missing key: {$key}"));
            }
        }

        if ($cached['version'] !== 2) {
            throw new CorruptedCacheDataException(__('Cached response version is not supported.'));
        }

        if (! is_string($cached['fingerprint']) || $cached['fingerprint'] === '') {
            throw new CorruptedCacheDataException(__('Cached response fingerprint is missing.'));
        }

        if (! is_bool($cached['replayable'])) {
            throw new CorruptedCacheDataException(__('Cached response replayable value must be a boolean.'));
        }

        try {
            return new CachedResponseValue(
                body: $cached['body'],
                status: $cached['status'],
                headers: $this->validateCachedHeaders($cached['headers']),
                path: $cached['path'],
                originalKey: $cached['originalKey'],
                fingerprint: $cached['fingerprint'],
                version: 2,
                replayable: $cached['replayable'],
            );
        } catch (\TypeError $exception) {
            throw new CorruptedCacheDataException(__('Cached response contains values of invalid types.'), $exception);
        }
    }

    private function handleCachedResponse(
        CachedResponseValue $cached,
        Request $request,
        string $idempotencyKey,
        IdempotencyOptions $options,
    ): Response {
        if (! hash_equals($cached->originalKey, $idempotencyKey)) {
            throw new MismatchedRequestException(__('The idempotency key resolved to a different cached request.'));
        }

        if ($cached->fingerprint === null || ! hash_equals($cached->fingerprint, $this->fingerprint->make($request))) {
            throw new MismatchedRequestException(
                __('Idempotency key previously used with different request parameters.'),
            );
        }

        if ($options->duplicateBehaviour === DuplicateBehaviour::EXCEPTION) {
            throw new DuplicateRequestException(__('Duplicate request detected.'));
        }

        if (! $cached->replayable) {
            throw new UnreplayableResponseException(
                __('The original request completed, but its response type cannot be replayed.'),
            );
        }

        return response($cached->body, $cached->status)
            ->withHeaders($this->filterHeaders($cached->headers))
            ->header(self::REPLAY_HEADER, $cached->originalKey);
    }

    /**
     * @return array<string, string|list<string>>
     */
    private function validateCachedHeaders(mixed $headers): array
    {
        if (! is_array($headers)) {
            throw new CorruptedCacheDataException(__('Cached response headers must be an array.'));
        }

        foreach ($headers as $name => $values) {
            if (! is_string($name) || (! is_string($values) && ! is_array($values))) {
                throw new CorruptedCacheDataException(__('Cached response headers contain an invalid value.'));
            }

            if (is_array($values)) {
                foreach ($values as $value) {
                    if (! is_string($value)) {
                        throw new CorruptedCacheDataException(__('Cached response headers contain an invalid value.'));
                    }
                }
            }
        }

        return $headers;
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     * @return array<string, string|list<string>>
     */
    private function filterHeaders(array $headers): array
    {
        return array_filter(
            $headers,
            static fn (string $name): bool => ! in_array(strtolower($name), self::EXCLUDED_RESPONSE_HEADERS, true),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
