<?php

namespace Square1\LaravelIdempotency\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

abstract class IdempotencyException extends HttpException {}
