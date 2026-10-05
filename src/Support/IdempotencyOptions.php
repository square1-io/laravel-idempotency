<?php

namespace Square1\LaravelIdempotency\Support;

use Square1\LaravelIdempotency\Enums\DuplicateBehaviour;
use Square1\LaravelIdempotency\Enums\IdempotencyScope;
use Square1\LaravelIdempotency\Enums\InFlightBehaviour;
use Square1\LaravelIdempotency\Enums\ResponseCategory;
use Square1\LaravelIdempotency\Exceptions\InvalidConfigurationException;

readonly class IdempotencyOptions
{
    /**
     * @param  list<string>  $enforcedVerbs
     * @param  array<string, bool>  $cacheStatuses
     */
    public function __construct(
        public int $cacheDuration,
        public string $header,
        public bool $ignoreEmptyKey,
        public array $enforcedVerbs,
        public DuplicateBehaviour $duplicateBehaviour,
        public InFlightBehaviour $inFlightBehaviour,
        public int $waitTimeout,
        public int $lockTtl,
        public IdempotencyScope $scope,
        public mixed $userIdResolver,
        public array $cacheStatuses,
        public ?string $cacheStore,
    ) {}

    public static function fromConfig(?string $inFlightOverride = null): self
    {
        $config = config('idempotency', []);

        if (! is_array($config)) {
            throw new InvalidConfigurationException('The idempotency configuration must be an array.');
        }

        $verbs = $config['enforced_verbs'] ?? [];
        if (! is_array($verbs)) {
            throw new InvalidConfigurationException('The enforced_verbs option must be an array.');
        }

        $validVerbs = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];
        foreach ($verbs as $verb) {
            if (! is_string($verb)) {
                throw new InvalidConfigurationException('Every enforced_verbs value must be a string.');
            }
        }

        $verbs = array_values(array_unique(array_map(strtoupper(...), $verbs)));

        foreach ($verbs as $verb) {
            if (! in_array($verb, $validVerbs, true)) {
                throw new InvalidConfigurationException(sprintf(
                    'Invalid HTTP verb in enforced_verbs: [%s]. Valid verbs are: %s.',
                    $verb,
                    implode(', ', $validVerbs),
                ));
            }
        }

        $cacheStatuses = $config['cache_statuses'] ?? [];
        if (! is_array($cacheStatuses)) {
            throw new InvalidConfigurationException('The cache_statuses option must be an array.');
        }

        $normalizedStatuses = [];
        foreach (ResponseCategory::cases() as $category) {
            $normalizedStatuses[$category->value] = self::boolean(
                $cacheStatuses[$category->value] ?? true,
                "cache_statuses.{$category->value}",
            );
        }

        foreach (array_keys($cacheStatuses) as $category) {
            if (ResponseCategory::tryFrom((string) $category) === null) {
                throw new InvalidConfigurationException("Invalid response category: [{$category}].");
            }
        }

        $cacheStore = $config['cache_store'] ?? null;
        if ($cacheStore !== null && (! is_string($cacheStore) || $cacheStore === '')) {
            throw new InvalidConfigurationException('The cache_store option must be null or a non-empty string.');
        }

        $header = $config['idempotency_header'] ?? null;
        if (! is_string($header) || $header === '') {
            throw new InvalidConfigurationException('The idempotency_header option must be a non-empty string.');
        }

        $duplicateBehaviour = DuplicateBehaviour::tryFrom((string) ($config['on_duplicate_behaviour'] ?? ''));
        if ($duplicateBehaviour === null) {
            throw new InvalidConfigurationException('The on_duplicate_behaviour option must be replay or exception.');
        }

        $inFlightBehaviour = InFlightBehaviour::tryFrom(
            $inFlightOverride === null || $inFlightOverride === ''
                ? (string) ($config['on_in_flight_behaviour'] ?? '')
                : $inFlightOverride,
        );
        if ($inFlightBehaviour === null) {
            throw new InvalidConfigurationException('The on_in_flight_behaviour option must be reject or wait.');
        }

        $scope = IdempotencyScope::tryFrom((string) ($config['scope'] ?? ''));
        if ($scope === null) {
            throw new InvalidConfigurationException('The scope option must be user, ip, or global.');
        }

        $waitTimeout = self::nonNegativeInteger($config, 'wait_timeout');
        if ($inFlightBehaviour === InFlightBehaviour::WAIT && $waitTimeout === 0) {
            throw new InvalidConfigurationException('The wait_timeout option must be at least 1 when waiting for in-flight requests.');
        }

        return new self(
            cacheDuration: self::positiveInteger($config, 'cache_duration'),
            header: $header,
            ignoreEmptyKey: self::boolean($config['ignore_empty_key'] ?? false, 'ignore_empty_key'),
            enforcedVerbs: $verbs,
            duplicateBehaviour: $duplicateBehaviour,
            inFlightBehaviour: $inFlightBehaviour,
            waitTimeout: $waitTimeout,
            lockTtl: self::positiveInteger($config, 'lock_ttl'),
            scope: $scope,
            userIdResolver: $config['user_id_resolver'] ?? null,
            cacheStatuses: $normalizedStatuses,
            cacheStore: $cacheStore,
        );
    }

    /** @param array<string, mixed> $config */
    private static function positiveInteger(array $config, string $key): int
    {
        $value = filter_var($config[$key] ?? null, FILTER_VALIDATE_INT);
        if ($value === false || $value < 1) {
            throw new InvalidConfigurationException("The {$key} option must be a positive integer.");
        }

        return $value;
    }

    /** @param array<string, mixed> $config */
    private static function nonNegativeInteger(array $config, string $key): int
    {
        $value = filter_var($config[$key] ?? null, FILTER_VALIDATE_INT);
        if ($value === false || $value < 0) {
            throw new InvalidConfigurationException("The {$key} option must be a non-negative integer.");
        }

        return $value;
    }

    private static function boolean(mixed $value, string $key): bool
    {
        $validated = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($validated === null) {
            throw new InvalidConfigurationException("The {$key} option must be a boolean.");
        }

        return $validated;
    }
}
