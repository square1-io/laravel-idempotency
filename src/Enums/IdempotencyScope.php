<?php

namespace Square1\LaravelIdempotency\Enums;

enum IdempotencyScope: string
{
    case USER = 'user';
    case IP = 'ip';
    case GLOBAL = 'global';
}
