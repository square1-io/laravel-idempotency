<?php

namespace Square1\LaravelIdempotency\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Http\Request;
use Square1\LaravelIdempotency\Providers\CachedResponseValue;
use Square1\LaravelIdempotency\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

class MiddlewareTest extends TestCase
{
    #[Test]
    public function it_handles_requests_with_idempotency_key()
    {
        $user = $this->getUnguardedUser(['field' => 'default']);
        $this->actingAs($user);
        $this->post('/user', ['field' => 'change'], ['Idempotency-Key' => 'unique-key-123'])
            ->assertStatus(Response::HTTP_OK)
            ->assertJson(['id' => $user->id, 'field' => 'change']);
    }

    #[Test]
    public function idempotency_header_only_appears_on_repeated_request()
    {
        $user = $this->getUnguardedUser(['field' => 'default']);
        $this->actingAs($user);
        $idempotencyKey = 'unique-key-123';
        $this->post('/user', ['field' => 'change'], ['Idempotency-Key' => $idempotencyKey])
            ->assertHeaderMissing('Idempotency-Relayed');

        $this->post('/user', ['field' => 'change_again'], ['Idempotency-Key' => $idempotencyKey])
            ->assertHeader('Idempotency-Relayed', $idempotencyKey);
    }

    #[Test]
    public function idempotency_header_missing_on_non_applicable_requests()
    {
        // Ensure GET isn't in the set, then make a GET request.
        config(['idempotency.enforced_verbs' => 'POST']);
        $user = $this->getUnguardedUser(['field' => 'default']);
        $this->actingAs($user)
            ->get('/user')
            ->assertHeaderMissing('Idempotency-Relayed');
    }

    #[Test]
    public function different_users_get_different_responses_for_same_idempotency_key()
    {
        $key = 'same-key-for-both-users';

        $user1 = $this->getUnguardedUser();
        $this->actingAs($user1);
        $firstResponse = $this->post(
            '/user',
            ['field' => 'change'],
            ['Idempotency-Key' => $key]
        );

        // Simulate the second user
        $user2 = $this->getUnguardedUser(['id' => 2, 'field' => 'different']);
        $this->actingAs($user2);
        $secondResponse = $this->post(
            '/user',
            ['field' => 'other_stuff'],
            ['Idempotency-Key' => $key]
        );

        $this->assertNotEquals($firstResponse->getContent(), $secondResponse->getContent());
    }

    #[Test]
    public function idempotency_key_creates_cache_entry()
    {
        $user = $this->getUnguardedUser();
        $this->actingAs($user);
        $key = 'unique-key-123';
        $this->post('/user', ['data' => 'value'], ['Idempotency-Key' => $key]);

        $cacheKey = 'idempotency:'.$user->id.':'.$key;
        $this->assertTrue(Cache::has($cacheKey));

        $cachedEntry = Cache::get($cacheKey);
        $this->assertInstanceOf(CachedResponseValue::class, $cachedEntry);
    }

    #[Test]
    public function using_same_idempotency_key_with_different_path_throws_exception()
    {
        $user = $this->getUnguardedUser();
        $this->actingAs($user);
        $key = 'unique-key-123';
        $this->post('/user', ['field' => 'changed'], ['Idempotency-Key' => $key]);
        $this->post('/account', ['field' => 'change-again'], ['Idempotency-Key' => $key])
            ->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJson(['class' => 'MismatchedPathException']);
    }

    #[Test]
    public function same_idempotency_key_returns_same_response_and_does_not_duplicate_change()
    {
        $user = $this->getUnguardedUser(['field' => 'default']);
        $this->actingAs($user);
        $key = 'unique-key-123';

        $firstResponse = $this->post('/user', ['field' => 'change'], ['Idempotency-Key' => $key]);
        $secondResponse = $this->post('/user', ['field' => 'should not change'], ['Idempotency-Key' => $key]);

        $firstResponse->assertStatus(Response::HTTP_OK);
        $secondResponse->assertStatus(Response::HTTP_OK);
        $this->assertEquals($firstResponse->getContent(), $secondResponse->getContent());
        $this->assertEquals('change', auth()->user()->field);
    }

    #[Test]
    public function idempotency_key_header_name_can_be_changed_in_config()
    {
        $customHeader = 'X-Custom-Key';
        config(['idempotency.idempotency_header' => $customHeader]);

        $user = $this->getUnguardedUser(['field' => 'default']);
        $this->actingAs($user);
        $key = 'unique-key-123';

        $firstResponse = $this->post('/user', ['field' => 'change'], [$customHeader => $key]);
        $secondResponse = $this->post('/user', ['field' => 'should not change'], [$customHeader => $key]);

        $firstResponse->assertStatus(Response::HTTP_OK);
        $secondResponse->assertStatus(Response::HTTP_OK);
        $this->assertEquals($firstResponse->getContent(), $secondResponse->getContent());
        $this->assertEquals('change', auth()->user()->field);
    }

    #[Test]
    public function missing_idempotency_key_causes_exception()
    {
        $user = $this->getUnguardedUser();
        $this->actingAs($user);

        $this->post('/user', ['field' => 'changed'])
            ->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJson(['class' => 'MissingIdempotencyKeyException']);
    }

    #[Test]
    public function config_change_allows_missing_idempotency_key()
    {
        config(['idempotency.ignore_empty_key' => true]);
        $user = $this->getUnguardedUser();
        $this->actingAs($user);
        $this->post('/user', ['field' => 'changed'])
            ->assertStatus(Response::HTTP_OK);
    }

    #[Test]
    public function request_without_authenticated_user_builds_key_ok()
    {
        $key = 'unique-key-123';
        $this->post('/account', [], ['Idempotency-Key' => $key]);

        $cacheKey = $this->getCacheKey($key);
        $this->assertTrue(Cache::has($cacheKey));

        $cachedEntry = Cache::get($cacheKey);
        $this->assertInstanceOf(CachedResponseValue::class, $cachedEntry);
    }

    #[Test]
    public function config_change_to_error_on_duplicate_works()
    {
        config(['idempotency.on_duplicate_behaviour' => \Square1\LaravelIdempotency\Enums\DuplicateBehaviour::EXCEPTION->value]);
        $user = $this->getUnguardedUser(['field' => 'default']);
        $this->actingAs($user);
        $key = 'unique-key-123';

        $firstResponse = $this->post('/user', ['field' => 'change'], ['Idempotency-Key' => $key]);
        $secondResponse = $this->post('/user', ['field' => 'should not change'], ['Idempotency-Key' => $key]);

        $firstResponse->assertStatus(Response::HTTP_OK);
        $secondResponse->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJson(['class' => 'DuplicateRequestException']);
    }

    #[Test]
    public function duplicate_request_timeout_works()
    {
        config(['idempotency.max_lock_wait_time' => 2]);
        $key = 'unique-key-123';
        $cacheKey = $this->getCacheKey($key);
        $lockKey = 'lock:'.$cacheKey;

        $lockMock = Mockery::mock();
        $lockMock->shouldReceive('get')->andReturn(false);
        // Mock the cache facade
        Cache::shouldReceive('lock')
            ->with($lockKey)
            ->andReturn($lockMock); // Simulate that the lock is present

        Cache::shouldReceive('has')
            ->with($cacheKey)
            ->andReturn(null);

        $this->post('/account', [], ['Idempotency-Key' => $key])
            ->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJson(['class' => 'LockWaitExceededException']);
    }

    #[Test]
    public function duplicate_request_polling_cache_returns_cached_value_once_available()
    {
        config(['idempotency.max_lock_wait_time' => 3]);
        $key = 'unique-key-123';
        $cacheKey = $this->getCacheKey($key);
        $lockKey = 'lock:'.$cacheKey;

        // Response that gets populated after first cache check failure
        $cacheResponse = new CachedResponseValue(
            '{"status":"Hello"}',
            200,
            ['Header' => 'Hi'],
            'account',
            $key,
        );

        $lockMock = Mockery::mock();
        $lockMock->shouldReceive('get')
            ->once()
            ->andReturn(false);
        // Mock the cache facade
        Cache::shouldReceive('lock')
            ->with($lockKey)
            ->andReturn($lockMock); // Simulate that the lock is present

        // Return false for first check, then on re-check, data is there
        Cache::shouldReceive('has')
            ->with($cacheKey)
            ->andReturn(false, true);
        Cache::shouldReceive('get')
            ->once()
            ->andReturn($cacheResponse);

        $this->post('/account', [], ['Idempotency-Key' => $key])
            ->assertStatus(Response::HTTP_OK)
            ->assertJson(['status' => 'Hello']);
    }

    #[Test]
    public function unrecognised_http_verb_throws_exception()
    {
        // Set an invalid HTTP verb in the config
        config(['idempotency.enforced_verbs' => ['POST', 'INVALID_VERB']]);

        $user = $this->getUnguardedUser();
        $this->actingAs($user);

        $this->post('/user', ['field' => 'test'], ['Idempotency-Key' => 'test-key'])
            ->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJson(['class' => 'InvalidConfigurationException']);
    }

    #[Test]
    public function unrecognised_duplicate_behaviour_throws_exception()
    {
        // Set an invalid duplicate behavior in the config
        config(['idempotency.on_duplicate_behaviour' => 'invalid_behavior']);

        $user = $this->getUnguardedUser();
        $this->actingAs($user);

        $this->post('/user', ['field' => 'test'], ['Idempotency-Key' => 'test-key'])
            ->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJson(['class' => 'InvalidConfigurationException']);
    }

    #[Test]
    public function it_throws_corrupted_cache_data_exception_for_invalid_cached_object()
    {
        $user = $this->getUnguardedUser();
        $this->actingAs($user);
        $key = 'corrupted-cache-key';
        $cacheKey = 'idempotency:'.$user->id.':'.$key;

        // Manually put a string into the cache
        Cache::put($cacheKey, 'this is not a valid cached object');

        $this->post('/user', ['field' => 'test'], ['Idempotency-Key' => $key])
            ->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJson(['class' => 'CorruptedCacheDataException']);
    }

    #[Test]
    public function it_handles_legacy_array_cache_format_successfully()
    {
        $user = $this->getUnguardedUser();
        $this->actingAs($user);
        $key = 'legacy-array-key';
        $cacheKey = 'idempotency:'.$user->id.':'.$key;

        $legacyCachedData = [
            'body' => json_encode(['message' => 'Hello from legacy cache']),
            'status' => Response::HTTP_OK,
            'headers' => ['x-custom-header' => ['legacy_value']],
            'path' => 'user',
            'originalKey' => $key,
        ];

        Cache::put($cacheKey, $legacyCachedData, config('idempotency.cache_duration'));

        $response = $this->post('/user', ['field' => 'test'], ['Idempotency-Key' => $key]);

        $response->assertStatus(Response::HTTP_OK)
            ->assertJson(['message' => 'Hello from legacy cache'])
            ->assertHeader('x-custom-header', 'legacy_value')
            ->assertHeader('Idempotency-Relayed', $key);
    }

    #[Test]
    public function it_handles_legacy_array_with_missing_keys()
    {
        $user = $this->getUnguardedUser();
        $this->actingAs($user);
        $key = 'legacy-missing-keys';
        $cacheKey = 'idempotency:'.$user->id.':'.$key;

        $legacyCachedData = [
            'body' => json_encode(['message' => 'Hello from legacy cache']),
            // Missing 'status', 'headers', 'path', 'originalKey'
        ];

        Cache::put($cacheKey, $legacyCachedData, config('idempotency.cache_duration'));

        $this->post('/user', ['field' => 'test'], ['Idempotency-Key' => $key])
            ->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJson(['class' => 'CorruptedCacheDataException']);
    }

    #[Test]
    public function it_handles_legacy_array_with_invalid_status()
    {
        $user = $this->getUnguardedUser();
        $this->actingAs($user);
        $key = 'legacy-invalid-status';
        $cacheKey = 'idempotency:'.$user->id.':'.$key;

        $legacyCachedData = [
            'body' => json_encode(['message' => 'Hello from legacy cache']),
            'status' => -1, // Invalid status
            'headers' => ['x-custom-header' => ['legacy_value']],
            'path' => 'user',
            'originalKey' => $key,
        ];

        Cache::put($cacheKey, $legacyCachedData, config('idempotency.cache_duration'));

        $this->post('/user', ['field' => 'test'], ['Idempotency-Key' => $key])
            ->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJson(['class' => 'InvalidCachedValueException']);
    }

    #[Test]
    public function it_handles_legacy_array_with_empty_path()
    {
        $user = $this->getUnguardedUser();
        $this->actingAs($user);
        $key = 'legacy-empty-path';
        $cacheKey = 'idempotency:'.$user->id.':'.$key;

        $legacyCachedData = [
            'body' => json_encode(['message' => 'Hello from legacy cache']),
            'status' => Response::HTTP_OK,
            'headers' => ['x-custom-header' => ['legacy_value']],
            'path' => '', // Empty path
            'originalKey' => $key,
        ];

        Cache::put($cacheKey, $legacyCachedData, config('idempotency.cache_duration'));

        $this->post('/user', ['field' => 'test'], ['Idempotency-Key' => $key])
            ->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJson(['class' => 'InvalidCachedValueException']);
    }

    private function getCacheKey(string $key)
    {
        $request = app(Request::class);
        $ipAddress = request()->ip();

        return "idempotency:{$ipAddress}:{$key}";
    }
}
