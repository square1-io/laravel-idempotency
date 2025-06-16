<?php

namespace Square1\LaravelIdempotency\Providers;

use Square1\LaravelIdempotency\Exceptions\InvalidCachedValueException;

readonly class CachedResponseValue
{
    public function __construct(
        public string $body,
        public int $status,
        public array $headers,
        public string $path,
        public string $originalKey
    ) {
        if ($status < 1) {
            throw new InvalidCachedValueException('Invalid HTTP status code provided for cached response. Status: '.$status);
        }

        if (empty($path)) {
            throw new InvalidCachedValueException('Cached response path cannot be empty.');
        }
        if (empty($originalKey)) {
            throw new InvalidCachedValueException('Cached response original key cannot be empty.');
        }
    }
}
