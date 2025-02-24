<?php

namespace Square1\LaravelIdempotency\Enums;

enum DuplicateBehaviour: string
{
    case REPLAY = 'replay';
    case EXCEPTION = 'exception';
}
