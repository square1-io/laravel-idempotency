<?php

namespace Square1\LaravelIdempotency\Tests;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Square1\LaravelIdempotency\Exceptions\IdempotencyException;

class TestCase extends OrchestraTestCase
{
    public int $controllerExecutionCount = 0;

    protected function resolveApplicationExceptionHandler($app)
    {
        $app->singleton(ExceptionHandler::class, CustomExceptionHandler::class);
    }

    protected function getEnvironmentSetUp($app)
    {
        Route::middleware('idempotency')->group(function () {
            Route::post('/user', function (Request $request) {
                $this->controllerExecutionCount++;
                $user = array_merge(auth()->user()->toArray(), $request->all());
                foreach ($request->all() as $key => $val) {
                    auth()->user()->{$key} = $val;
                }

                return response()->json($user);
            });
            Route::get('/user', function () {
                return response()->json(auth()->user());
            });
            Route::match(['POST', 'PUT'], '/account', function (Request $request) {
                $this->controllerExecutionCount++;

                return response()->json([
                    'message' => 'success',
                    'payload' => $request->all(),
                    'user_id' => $request->user()?->getAuthIdentifier(),
                ]);
            });
            Route::post('/other-account', function () {
                $this->controllerExecutionCount++;

                return response()->json(['message' => 'other']);
            });
            Route::post('/throws-once', function () {
                $this->controllerExecutionCount++;

                if (Cache::increment('throws-once-count') === 1) {
                    throw new \RuntimeException('Controller failed.');
                }

                return response()->json(['message' => 'recovered']);
            });
            Route::post('/headers', function () {
                $this->controllerExecutionCount++;

                return response('response body')
                    ->header('X-Safe', 'kept')
                    ->withCookie(cookie('session-test', 'secret'));
            });
            Route::post('/stream', function () {
                $this->controllerExecutionCount++;

                return response()->stream(static function (): void {
                    echo 'streamed';
                });
            });
            Route::post('/client-error', function () {
                $this->controllerExecutionCount++;

                return response()->json(['error' => 'invalid'], 422);
            });
            Route::post('/attributes', function (Request $request) {
                return response()->json([
                    'idempotent' => $request->attributes->get('idempotent'),
                    'key' => $request->attributes->get('idempotency-key'),
                    'scope' => $request->attributes->get('idempotency-scope'),
                ]);
            });
            Route::post('/upload', function (Request $request) {
                $this->controllerExecutionCount++;

                return response()->json([
                    'name' => $request->file('document')?->getClientOriginalName(),
                ]);
            });
        });
    }

    protected function getPackageProviders($app)
    {
        return ['Square1\LaravelIdempotency\IdempotencyServiceProvider'];
    }

    protected function defineRoutes($router)
    {
        $router->post('/test-route', function () {
            return response()->json(['message' => 'Success']);
        })->middleware('idempotency');
    }

    protected function getUnguardedUser($options = []): User
    {
        $defaults = [
            'id' => 1,
            'field' => 'test',
        ];
        $fields = array_merge($defaults, $options);
        $user = new User;
        $user->unguard(); // Temporarily un-guard attributes
        $user->fill($fields);

        return $user;
    }
}

class CustomExceptionHandler extends Handler
{
    public function report(\Throwable $e) {}

    public function render($request, \Throwable $e)
    {
        if ($e instanceof IdempotencyException) {
            return response()->json([
                'error' => $e->getMessage(),
                'class' => class_basename($e),
            ], $e->getStatusCode(), $e->getHeaders());
        }

        return response()->json([
            'error' => $e->getMessage(),
            'class' => class_basename($e),
        ], 500);
    }
}
