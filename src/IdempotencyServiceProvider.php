<?php

namespace Square1\LaravelIdempotency;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Square1\LaravelIdempotency\Http\Middleware\IdempotencyMiddleware;

class IdempotencyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/config/idempotency.php' => config_path('idempotency.php'),
        ], ['idempotency', 'idempotency-config']);

        $this->app->make(Router::class)->aliasMiddleware('idempotency', IdempotencyMiddleware::class);
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/config/idempotency.php', 'idempotency'
        );
    }
}
