<?php

namespace Square1\LaravelIdempotency\Exceptions;

class MissingIdempotencyKeyException extends IdempotencyException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct(400, $message, $previous);
    }
}
