<?php

namespace Square1\LaravelIdempotency\Providers;

use Square1\LaravelIdempotency\Exceptions\InvalidCachedValueException;

readonly class CachedResponseValue
{
    /**
     * @param  array<string, string|list<string>>  $headers
     */
    public function __construct(
        public ?string $body,
        public int $status,
        public array $headers,
        public string $path,
        public string $originalKey,
        public ?string $fingerprint = null,
        public int $version = 1,
        public bool $replayable = true,
    ) {
        if ($status < 100 || $status > 599) {
            throw new InvalidCachedValueException('Invalid HTTP status code provided for cached response. Status: '.$status);
        }

        if ($path === '') {
            throw new InvalidCachedValueException('Cached response path cannot be empty.');
        }
        if ($originalKey === '') {
            throw new InvalidCachedValueException('Cached response original key cannot be empty.');
        }
        if ($replayable && $body === null) {
            throw new InvalidCachedValueException('A replayable cached response must contain a body.');
        }
        if ($version < 1) {
            throw new InvalidCachedValueException('Cached response version must be a positive integer.');
        }

    }
}
