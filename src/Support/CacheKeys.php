<?php

namespace Square1\LaravelIdempotency\Support;

class CacheKeys
{
    public function response(string $scope, string $idempotencyKey): string
    {
        return 'idempotency:response:v2:'.hash('sha256', serialize([$scope, $idempotencyKey]));
    }

    public function lock(string $responseKey): string
    {
        return 'idempotency:lock:v2:'.hash('sha256', $responseKey);
    }
}
