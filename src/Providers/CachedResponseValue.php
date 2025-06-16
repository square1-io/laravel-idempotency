<?php

namespace Square1\LaravelIdempotency\Providers;

use Square1\LaravelIdempotency\Exceptions\InvalidArgumentException;

readonly class CachedResponseValue
{
    public function __construct(
        public string $body,
        public int $status,
        public array $headers,
        public string $path,
        public string $originalKey
    ) {
        if (empty($body)) {
            throw new InvalidArgumentException('Cached response body cannot be empty.');
        }

        if ($status < 1) {
            throw new InvalidArgumentException('Invalid HTTP status code provided for cached response. Status: '.$status);
        }

        if (empty($path)) {
            throw new InvalidArgumentException('Cached response path cannot be empty.');
        }
        if (empty($originalKey)) {
            throw new InvalidArgumentException('Cached response original key cannot be empty.');
        }
    }
}
