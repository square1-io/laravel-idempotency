<?php

namespace Square1\LaravelIdempotency\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Square1\LaravelIdempotency\Tests\CustomUserIdResolver;
use Square1\LaravelIdempotency\Tests\TestCase;

class ResolveUserIdFromConfigTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        // Set the package configuration to use the custom user ID resolver
        $app['config']->set('idempotency.user_id_resolver', [CustomUserIdResolver::class, 'resolveUserId']);

        parent::getEnvironmentSetup($app);
    }

    #[Test]
    public function it_uses_custom_user_id_resolver_from_config()
    {
        $key = 'unique-key-123';
        $user = $this->getUnguardedUser();
        $this->actingAs($user);
        $this->post('/user', ['data' => 'value'], ['Idempotency-Key' => $key]);

        // Custom resolver implements custom id logic for key building
        $cacheKey = 'idempotency:custom-user-id:'.$key;
        $this->assertTrue(Cache::has($cacheKey));

        $cachedEntry = Cache::get($cacheKey);
        $this->assertNotNull($cachedEntry);
    }
}
