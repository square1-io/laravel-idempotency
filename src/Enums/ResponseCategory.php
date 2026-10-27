<?php

namespace Square1\LaravelIdempotency\Enums;

enum ResponseCategory: string
{
    case INFORMATIONAL = 'informational';
    case SUCCESS = 'success';
    case REDIRECTION = 'redirection';
    case CLIENT_ERROR = 'client_error';
    case SERVER_ERROR = 'server_error';

    public static function fromStatusCode(int $status): self
    {
        return match (true) {
            $status >= 500 => self::SERVER_ERROR,
            $status >= 400 => self::CLIENT_ERROR,
            $status >= 300 => self::REDIRECTION,
            $status >= 200 => self::SUCCESS,
            default => self::INFORMATIONAL,
        };
    }
}
