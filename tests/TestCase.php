<?php

namespace Square1\LaravelIdempotency\Tests;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Square1\LaravelIdempotency\Exceptions\DuplicateRequestException;
use Square1\LaravelIdempotency\Exceptions\LockExceededException;
use Square1\LaravelIdempotency\Exceptions\MismatchedPathException;
use Square1\LaravelIdempotency\Exceptions\MissingIdempotencyKeyException;
use Square1\LaravelIdempotency\Http\Middleware\IdempotencyMiddleware;
use Symfony\Component\HttpFoundation\Response;

class TestCase extends OrchestraTestCase
{
    protected function resolveApplicationExceptionHandler($app)
    {
        $app->singleton(ExceptionHandler::class, CustomExceptionHandler::class);
    }

    // Set up environment for testing
    protected function getEnvironmentSetUp($app)
    {
        // Register the middleware
        $app['router']->aliasMiddleware('idempotency', IdempotencyMiddleware::class);

        // Define your test routes
        Route::middleware('idempotency')->group(function () {
            Route::post('/user', function (\Illuminate\Http\Request $request) {
                $user = array_merge(auth()->user()->toArray(), $request->all());
                foreach ($request->all() as $key => $val) {
                    auth()->user()->{$key} = $val;
                }

                return response()->json($user);
            });
            Route::get('/user', function () {
                return response()->json(auth()->user());
            });
            Route::post('/account', function () {
                return response()->json(['message' => 'success']);
            });
        });
    }

    // Register package service provider
    protected function getPackageProviders($app)
    {
        return ['Square1\LaravelIdempotency\IdempotencyServiceProvider'];
    }

    // Define routes for testing
    protected function defineRoutes($router)
    {
        $router->post('/test-route', function () {
            return response()->json(['message' => 'Success']);
        })->middleware('idempotency');
    }

    protected function getUnguardedUser($options = []): \Illuminate\Foundation\Auth\User
    {
        $defaults = [
            'id' => 1,
            'field' => 'test',
        ];
        $fields = array_merge($defaults, $options);
        $user = new \Illuminate\Foundation\Auth\User();
        $user->unguard(); // Temporarily un-guard attributes
        $user->fill($fields);

        return $user;
    }
}

class CustomExceptionHandler extends Handler
{
    public function report(\Throwable $e)
    {
    }

    public function render($request, \Throwable $e)
    {
        if ($e instanceof MismatchedPathException
            || $e instanceof MissingIdempotencyKeyException
            || $e instanceof DuplicateRequestException
            || $e instanceof LockExceededException) {
            return response()->json([
                'error' => $e->getMessage(),
                'class' => class_basename($e),
            ], Response::HTTP_BAD_REQUEST);
        }

        return parent::render($request, $e);
    }
}
