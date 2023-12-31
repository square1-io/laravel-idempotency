<?php

namespace Square1\LaravelIdempotency\Tests;

class CustomUserIdResolver
{
    public function resolveUserId()
    {
        // Custom logic to resolve user ID
        return 'custom-user-id';
    }
}
