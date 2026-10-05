<?php

namespace Square1\LaravelIdempotency\Tests\Integration;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Square1\LaravelIdempotency\Support\CacheKeys;
use Square1\LaravelIdempotency\Tests\TestCase;

class RedisLockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('IDEMPOTENCY_REDIS_TESTS') !== '1') {
            $this->markTestSkipped('Redis integration tests are disabled.');
        }

        config([
            'database.redis.client' => 'phpredis',
            'database.redis.options.prefix' => 'laravel-idempotency-tests:',
            'database.redis.default' => [
                'host' => '127.0.0.1',
                'password' => null,
                'port' => 6379,
                'database' => 15,
            ],
            'cache.stores.idempotency-redis' => [
                'driver' => 'redis',
                'connection' => 'default',
                'lock_connection' => 'default',
            ],
            'idempotency.cache_store' => 'idempotency-redis',
        ]);

        Cache::purge('idempotency-redis');

        try {
            Cache::store('idempotency-redis')->get('connection-check');
        } catch (\Throwable) {
            $this->markTestSkipped('Redis is not available.');
        }
    }

    #[Test]
    public function redis_in_flight_locks_return_conflict(): void
    {
        $key = 'redis-in-flight-'.bin2hex(random_bytes(8));
        $cacheKeys = app(CacheKeys::class);
        $responseKey = $cacheKeys->response('ip:127.0.0.1', $key);
        $store = Cache::store('idempotency-redis')->getStore();
        $this->assertInstanceOf(LockProvider::class, $store);
        $lock = $store->lock($cacheKeys->lock($responseKey), 30);
        $this->assertTrue($lock->get());

        try {
            $this->post('/account', [], ['Idempotency-Key' => $key])
                ->assertConflict()
                ->assertHeader('Retry-After', '1');
        } finally {
            $lock->release();
        }
    }

    #[Test]
    public function redis_locks_are_released_after_downstream_exceptions(): void
    {
        $key = 'redis-exception-'.bin2hex(random_bytes(8));
        $cacheKeys = app(CacheKeys::class);
        $responseKey = $cacheKeys->response('ip:127.0.0.1', $key);

        $this->post('/throws-once', [], ['Idempotency-Key' => $key])->assertInternalServerError();

        $store = Cache::store('idempotency-redis')->getStore();
        $this->assertInstanceOf(LockProvider::class, $store);
        $probe = $store->lock($cacheKeys->lock($responseKey), 30);
        $this->assertTrue($probe->get());
        $probe->release();
    }
}
