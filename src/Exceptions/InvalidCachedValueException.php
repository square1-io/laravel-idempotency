<?php

namespace Square1\LaravelIdempotency\Exceptions;

class InvalidCachedValueException extends IdempotencyException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct(500, $message, $previous);
    }
}
