<?php

namespace Square1\LaravelIdempotency\Tests;

class CustomUserIdResolver
{
    public function resolveUserId()
    {
        return 'custom-user-id';
    }
}
