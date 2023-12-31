<?php

namespace Square1\LaravelIdempotency\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Square1\LaravelIdempotency\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

class MiddlewareTest extends TestCase
{
    /** @test */
    public function it_handles_requests_with_idempotency_key()
    {
        $user = $this->getUnguardedUser(['field' => 'default']);
        $this->actingAs($user);
        $response = $this->post('/user', ['field' => 'change'], ['Idempotency-Key' => 'unique-key-123']);

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJson(['id' => $user->id, 'field' => 'change']);
    }

    /** @test */
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

    /** @test */
    public function idempotency_header_missing_on_non_applicable_requests()
    {
        // Ensure GET isn't in the set, then make a GET request.
        config(['idempotency.enforced_verbs' => 'POST']);
        $user = $this->getUnguardedUser(['field' => 'default']);
        $this->actingAs($user);
        $response = $this->get('/user');

        $response->assertHeaderMissing('Idempotency-Relayed');
    }

    /** @test */
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

    /** @test */
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

    /** @test */
    public function using_same_idempotency_key_with_different_path_throws_exception()
    {
        $user = $this->getUnguardedUser();
        $this->actingAs($user);
        $key = 'unique-key-123';
        $this->post('/user', ['field' => 'changed'], ['Idempotency-Key' => $key]);
        $response = $this->post('/account', ['field' => 'change-again'], ['Idempotency-Key' => $key]);

        $response->assertStatus(Response::HTTP_BAD_REQUEST);
    }

    /** @test */
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

    /** @test */
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

    /** @test */
    public function missing_idempotency_key_causes_exception()
    {
        $user = $this->getUnguardedUser();
        $this->actingAs($user);
        $key = 'unique-key-123';
        $response = $this->post('/user', ['field' => 'changed']);

        $response->assertStatus(Response::HTTP_BAD_REQUEST);
    }

    /** @test */
    public function config_change_allows_missing_idempotency_key()
    {
        config(['idempotency.ignore_empty_key' => true]);
        $user = $this->getUnguardedUser();
        $this->actingAs($user);
        $key = 'unique-key-123';
        $response = $this->post('/user', ['field' => 'changed']);

        $response->assertStatus(Response::HTTP_OK);
    }

    /** @test */
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

    /** @test */
    public function config_change_to_error_on_duplicate_works()
    {
        config(['idempotency.on_duplicate_behaviour' => 'exception']);
        $user = $this->getUnguardedUser(['field' => 'default']);
        $this->actingAs($user);
        $key = 'unique-key-123';

        $firstResponse = $this->post('/user', ['field' => 'change'], ['Idempotency-Key' => $key]);
        $secondResponse = $this->post('/user', ['field' => 'should not change'], ['Idempotency-Key' => $key]);

        $firstResponse->assertStatus(Response::HTTP_OK);
        $secondResponse->assertStatus(Response::HTTP_BAD_REQUEST);
    }
}
