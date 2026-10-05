<?php

namespace Square1\LaravelIdempotency\Enums;

enum InFlightBehaviour: string
{
    case REJECT = 'reject';
    case WAIT = 'wait';
}
