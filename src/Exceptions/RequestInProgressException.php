<?php

namespace Square1\LaravelIdempotency\Exceptions;

class RequestInProgressException extends IdempotencyException
{
    public function __construct(string $message, int $retryAfter = 1, ?\Throwable $previous = null)
    {
        parent::__construct(409, $message, $previous, ['Retry-After' => (string) $retryAfter]);
    }
}
