<?php

namespace Square1\LaravelIdempotency\Support;

use Square1\LaravelIdempotency\Enums\IdempotencyScope;

readonly class ResolvedScope
{
    public function __construct(
        public IdempotencyScope $scope,
        public string $identifier,
    ) {}

    public function cacheValue(): string
    {
        return $this->scope->value.':'.$this->identifier;
    }
}
