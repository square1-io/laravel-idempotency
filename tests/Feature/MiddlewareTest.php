<?php

namespace Square1\LaravelIdempotency\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Square1\LaravelIdempotency\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

class MiddlewareTest extends TestCase
{
    #[Test]
    public function it_handles_requests_with_idempotency_key()
    {
        $user = $this->getUnguardedUser(['field' => 'default']);
        $this->actingAs($user);
        $response = $this->post('/user', ['field' => 'change'], ['Idempotency-Key' => 'unique-key-123']);

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJson(['id' => $user->id, 'field' => 'change']);
    }

    #[Test]
    public function idempotency_header_only_appears_on_repeated_request()
    {
        $user = $this->getUnguardedUser(['field' => 'default']);
        $this->actingAs($user);
        $idempotencyKey = 'unique-key-123';
        $response = $this->post('/user', ['field' => 'change'], ['Idempotency-Key' => $idempotencyKey]);
        $response->assertHeaderMissing('Idempotency-Relayed');

        $response = $this->post('/user', ['field' => 'change_again'], ['Idempotency-Key' => $idempotencyKey]);
        $response->assertHeader('Idempotency-Relayed', $idempotencyKey);
    }

    #[Test]
    public function idempotency_header_missing_on_non_applicable_requests()
    {
        // Ensure GET isn't in the set, then make a GET request.
        config(['idempotency.enforced_verbs' => 'POST']);
        $user = $this->getUnguardedUser(['field' => 'default']);
        $this->actingAs($user);
        $response = $this->get('/user');

        $response->assertHeaderMissing('Idempotency-Relayed');
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
        $this->assertArrayHasKey('body', $cachedEntry);
        $this->assertArrayHasKey('status', $cachedEntry);
        $this->assertArrayHasKey('headers', $cachedEntry);
        $this->assertArrayHasKey('originalKey', $cachedEntry);
        $this->assertArrayHasKey('path', $cachedEntry);
    }

    #[Test]
    public function using_same_idempotency_key_with_different_path_throws_exception()
    {
        $user = $this->getUnguardedUser();
        $this->actingAs($user);
        $key = 'unique-key-123';
        $this->post('/user', ['field' => 'changed'], ['Idempotency-Key' => $key]);
        $response = $this->post('/account', ['field' => 'change-again'], ['Idempotency-Key' => $key]);

        $response->assertStatus(Response::HTTP_BAD_REQUEST)
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
        $key = 'unique-key-123';
        $response = $this->post('/user', ['field' => 'changed']);

        $response->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJson(['class' => 'MissingIdempotencyKeyException']);
    }

    #[Test]
    public function config_change_allows_missing_idempotency_key()
    {
        config(['idempotency.ignore_empty_key' => true]);
        $user = $this->getUnguardedUser();
        $this->actingAs($user);
        $key = 'unique-key-123';
        $response = $this->post('/user', ['field' => 'changed']);

        $response->assertStatus(Response::HTTP_OK);
    }

    #[Test]
    public function request_without_authenticated_user_builds_key_ok()
    {
        $key = 'unique-key-123';
        $response = $this->post('/account', [], ['Idempotency-Key' => $key]);

        $cacheKey = 'idempotency:global:'.$key;
        $this->assertTrue(Cache::has($cacheKey));

        $cachedEntry = Cache::get($cacheKey);
        $this->assertArrayHasKey('body', $cachedEntry);
        $this->assertArrayHasKey('status', $cachedEntry);
        $this->assertArrayHasKey('headers', $cachedEntry);
        $this->assertArrayHasKey('path', $cachedEntry);
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
        $cacheKey = 'idempotency:global:'.$key;

        $lockMock = Mockery::mock();
        $lockMock->shouldReceive('get')->andReturn(false);
        // Mock the cache facade
        Cache::shouldReceive('lock')
            ->with($cacheKey, config('idempotency.max_lock_wait_time'))
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
        $cacheKey = 'idempotency:global:'.$key;

        // Response that gets populated after first cache check failure
        $cacheResponse = [
            'body' => '{"status":"Hello"}',
            'path' => 'account',
            'headers' => ['Header' => 'Hi'],
            'status' => 200,
            'originalKey' => $key,
        ];

        $lockMock = Mockery::mock();
        $lockMock->shouldReceive('get')
            ->once()
            ->andReturn(false);
        // Mock the cache facade
        Cache::shouldReceive('lock')
            ->with($cacheKey, config('idempotency.max_lock_wait_time'))
            ->andReturn($lockMock); // Simulate that the lock is present

        // Return false for first check, then on re-check, data is there
        Cache::shouldReceive('has')
            ->with($cacheKey)
            ->andReturn(false, true);
        Cache::shouldReceive('get')
            ->once()
            ->andReturn($cacheResponse);

        $response = $this->post('/account', [], ['Idempotency-Key' => $key])
            ->assertStatus(Response::HTTP_OK)
            ->assertJson(['status' => 'Hello']);
    }
}
