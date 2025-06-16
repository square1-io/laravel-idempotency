<?php

namespace Square1\LaravelIdempotency\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Square1\LaravelIdempotency\Enums\DuplicateBehaviour;
use Square1\LaravelIdempotency\Exceptions\CorruptedCacheDataException;
use Square1\LaravelIdempotency\Exceptions\DuplicateRequestException;
use Square1\LaravelIdempotency\Exceptions\InvalidConfigurationException;
use Square1\LaravelIdempotency\Exceptions\LockWaitExceededException;
use Square1\LaravelIdempotency\Exceptions\MismatchedPathException;
use Square1\LaravelIdempotency\Exceptions\MissingIdempotencyKeyException;
use Square1\LaravelIdempotency\Providers\CachedResponseValue;

class IdempotencyMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $this->validateConfig();

        // If we are getting a verb we don't care about, pass the request straight through
        if (! in_array($request->method(), config('idempotency.enforced_verbs'))) {
            return $next($request);
        }

        $idempotencyKey = $request->header(config('idempotency.idempotency_header'));

        if (empty($idempotencyKey)) {
            if (config('idempotency.ignore_empty_key')) {
                return $next($request);
            } else {
                throw new MissingIdempotencyKeyException(__('Idempotency key "'.config('idempotency.idempotency_header').'" not found.'));
            }
        }

        // Get the user ID resolver from the configuration.
        // If not set, use a default closure that returns the authenticated user's ID.
        $userIdResolver = config('idempotency.user_id_resolver', function () {
            return auth()->check() ? auth()->user()->id : null;
        });

        // Invoke the resolver to get the user ID.
        $userId = $userIdResolver instanceof Closure ? $userIdResolver() : $this->resolveUserIdFromConfig($userIdResolver);

        $cacheKey = $this->buildCacheKey($userId, $idempotencyKey);

        if (Cache::has($cacheKey)) {
            return $this->handleCachedResponse($cacheKey, $request);
        }

        $lock = Cache::lock($this->buildLockKey($cacheKey));

        if (! $lock->get()) {
            return $this->waitForCacheLock($cacheKey, $request);
        }

        $response = $this->processRequest($request, $cacheKey, $next);
        $lock->release();

        return $response;
    }

    private function waitForCacheLock(string $cacheKey, Request $request)
    {
        $maxWait = config('idempotency.max_lock_wait_time', 1);
        $tries = 0;
        $maxTries = $maxWait - 1;
        $sleep = 1;

        while ($tries < $maxTries) {
            if (Cache::has($cacheKey)) {
                // The response is now cached, break the loop
                return $this->handleCachedResponse($cacheKey, $request);
            }

            // Wait for a bit before checking again
            sleep($sleep);
            $tries++;
        }

        // Throw an exception if the response is not available after waiting
        throw new LockWaitExceededException(__('Lock wait time of '.$maxWait.' seconds exceeded.'));
    }

    /**
     * Resolve the user ID from a config value if it's not a closure.
     *
     * @param  mixed  $userIdResolver
     * @return mixed
     */
    protected function resolveUserIdFromConfig($userIdResolver)
    {
        if (is_array($userIdResolver) && count($userIdResolver) === 2) {
            // Assuming the configuration is in the format [ClassName::class, 'methodName']
            [$class, $method] = $userIdResolver;

            return app($class)->$method();
        }

        // Default to authenticated user's ID if the configuration is not a valid callable
        return auth()->check() ? auth()->user()->id : 'global';
    }

    protected function buildCacheKey(string $userId, string $idempotencyKey): string
    {
        return "idempotency:{$userId}:{$idempotencyKey}";
    }

    protected function buildLockKey(string $cacheKey): string
    {
        return "lock:{$cacheKey}";
    }

    protected function processRequest(Request $request, string $cacheKey, Closure $next)
    {
        $response = $next($request);

        Cache::put($cacheKey, new CachedResponseValue(
            $response->getContent(),
            $response->getStatusCode(),
            $response->headers->all(),
            $request->path(),
            $request->header(config('idempotency.idempotency_header')),
        ), config('idempotency.cache_duration'));

        return $response;
    }

    protected function handleCachedResponse(string $cacheKey, Request $request)
    {
        $cachedValue = Cache::get($cacheKey);

        if (! $cachedValue instanceof CachedResponseValue) {
            if (is_array($cachedValue)) {
                try {
                    // Check for essential keys before attempting construction
                    $requiredKeys = ['body', 'status', 'headers', 'path', 'originalKey'];
                    foreach ($requiredKeys as $key) {
                        if (! array_key_exists($key, $cachedValue)) {
                            throw new CorruptedCacheDataException(__("Legacy cached array is missing key: {$key}"));
                        }
                    }
                    $cachedValue = new CachedResponseValue(
                        $cachedValue['body'],
                        $cachedValue['status'],
                        $cachedValue['headers'],
                        $cachedValue['path'],
                        $cachedValue['originalKey']
                    );
                } catch (CorruptedCacheDataException $e) {
                    throw $e;
                }
            } else {
                // If it's not an array and not a CachedResponseValue, it's unexpected.
                throw new CorruptedCacheDataException(__('Unexpected cache payload found. Expected CachedResponseValue or legacy array.'));
            }
        }
        // By this point, $cachedValue is guaranteed to be a valid CachedResponseValue object
        // because the constructor would have thrown an exception if validation failed.

        if ($request->path() != $cachedValue->path) {
            throw new MismatchedPathException(__('Idempotency key previously used on different route ('.$cachedValue->path.').'));
        }

        // Config option to throw exception on duplicate?
        if (config('idempotency.on_duplicate_behaviour') == DuplicateBehaviour::EXCEPTION->value) {
            throw new DuplicateRequestException(__('Duplicate request detected.'));
        }

        return response($cachedValue->body, $cachedValue->status)
            ->withHeaders($cachedValue->headers)
            ->header('Idempotency-Relayed', $cachedValue->originalKey);
    }

    /**
     * Validate that the configuration values are valid
     *
     * @throws InvalidConfigurationException
     */
    protected function validateConfig()
    {
        $behaviour = config('idempotency.on_duplicate_behaviour');

        try {
            // This will throw a ValueError if the behavior is not valid
            DuplicateBehaviour::from($behaviour);
        } catch (\ValueError $e) {
            $validOptions = implode(', ', array_column(DuplicateBehaviour::cases(), 'value'));
            throw new InvalidConfigurationException(
                "Invalid idempotency duplicate behavior: '{$behaviour}'. Valid options are: {$validOptions}"
            );
        }

        // You can add similar validation for other config values if needed
        $enforced_verbs = config('idempotency.enforced_verbs', []);
        $valid_verbs = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

        foreach ($enforced_verbs as $verb) {
            if (! in_array(strtoupper($verb), $valid_verbs)) {
                throw new InvalidConfigurationException(
                    "Invalid HTTP verb in enforced_verbs: '{$verb}'. Valid verbs are: ".
                    implode(', ', $valid_verbs)
                );
            }
        }
    }
}
