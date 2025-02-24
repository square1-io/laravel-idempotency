<?php

namespace Square1\LaravelIdempotency\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Square1\LaravelIdempotency\Enums\DuplicateBehaviour;
use Square1\LaravelIdempotency\Exceptions\DuplicateRequestException;
use Square1\LaravelIdempotency\Exceptions\LockWaitExceededException;
use Square1\LaravelIdempotency\Exceptions\MismatchedPathException;
use Square1\LaravelIdempotency\Exceptions\MissingIdempotencyKeyException;

class IdempotencyMiddleware
{
    public function handle(Request $request, Closure $next)
    {
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

        $lock = Cache::lock($cacheKey, config('idempotency.max_lock_wait_time', 1));

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

    protected function processRequest(Request $request, string $cacheKey, Closure $next)
    {
        $response = $next($request);

        Cache::put($cacheKey, [
            'body' => $response->getContent(),
            'status' => $response->getStatusCode(),
            'headers' => $response->headers->all(),
            'path' => $request->path(),
            'originalKey' => $request->header(config('idempotency.idempotency_header')),
        ], config('idempotency.cache_duration'));

        return $response;
    }

    protected function handleCachedResponse(string $cacheKey, Request $request)
    {
        $cachedData = Cache::get($cacheKey);
        if ($request->path() != $cachedData['path']) {
            throw new MismatchedPathException(__('Idempotency key previously used on different route ('.$cachedData['path'].').'));
        }

        // Config option to throw exception on duplicate?
        if (config('idempotency.on_duplicate_behaviour') == DuplicateBehaviour::EXCEPTION->value) {
            throw new DuplicateRequestException(__('Duplicate request detected.'));
        }

        return response($cachedData['body'], $cachedData['status'])
            ->withHeaders($cachedData['headers'])
            ->header('Idempotency-Relayed', $cachedData['originalKey']);
    }
}
