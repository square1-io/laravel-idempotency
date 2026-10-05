<?php

namespace Square1\LaravelIdempotency\Exceptions;

class DuplicateRequestException extends IdempotencyException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct(409, $message, $previous);
    }
}
