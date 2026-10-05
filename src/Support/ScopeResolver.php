<?php

namespace Square1\LaravelIdempotency\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Square1\LaravelIdempotency\Enums\IdempotencyScope;
use Square1\LaravelIdempotency\Exceptions\InvalidConfigurationException;

class ScopeResolver
{
    public function resolve(Request $request, IdempotencyOptions $options): ResolvedScope
    {
        return match ($options->scope) {
            IdempotencyScope::USER => $this->resolveUser($request, $options->userIdResolver),
            IdempotencyScope::IP => $this->resolveIp($request),
            IdempotencyScope::GLOBAL => new ResolvedScope(IdempotencyScope::GLOBAL, ''),
        };
    }

    private function resolveUser(Request $request, mixed $configuredResolver): ResolvedScope
    {
        if ($configuredResolver !== null) {
            $identifier = $this->callConfiguredResolver($configuredResolver, $request);

            return new ResolvedScope(IdempotencyScope::USER, $this->normalizeIdentifier($identifier));
        }

        $user = $request->user();
        if ($user instanceof Authenticatable) {
            $identifier = $user->getAuthIdentifier();
            if (is_scalar($identifier) && (string) $identifier !== '') {
                return new ResolvedScope(IdempotencyScope::USER, (string) $identifier);
            }
        }

        return $this->resolveIp($request);
    }

    private function callConfiguredResolver(mixed $resolver, Request $request): mixed
    {
        if (is_array($resolver) && count($resolver) === 2 && is_string($resolver[0])) {
            $resolver = [app($resolver[0]), $resolver[1]];
        }

        if (! is_callable($resolver)) {
            throw new InvalidConfigurationException('The user_id_resolver option must be callable.');
        }

        return app()->call($resolver, ['request' => $request]);
    }

    private function normalizeIdentifier(mixed $identifier): string
    {
        if (! is_scalar($identifier) || (string) $identifier === '') {
            throw new InvalidConfigurationException('The user ID resolver must return a non-empty scalar value.');
        }

        return (string) $identifier;
    }

    private function resolveIp(Request $request): ResolvedScope
    {
        $ip = $request->ip();
        if (! is_string($ip) || $ip === '') {
            throw new InvalidConfigurationException('The request IP address could not be resolved.');
        }

        return new ResolvedScope(IdempotencyScope::IP, $ip);
    }
}
