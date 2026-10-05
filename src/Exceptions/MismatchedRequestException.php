<?php

namespace Square1\LaravelIdempotency\Exceptions;

class MismatchedRequestException extends IdempotencyException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct(422, $message, $previous);
    }
}
