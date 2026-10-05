<?php

namespace Square1\LaravelIdempotency\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Square1\LaravelIdempotency\Enums\DuplicateBehaviour;
use Square1\LaravelIdempotency\Support\RequestFingerprint;
use Square1\LaravelIdempotency\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

class MiddlewareTest extends TestCase
{
    #[Test]
    public function it_handles_requests_with_an_idempotency_key(): void
    {
        $user = $this->getUnguardedUser(['field' => 'default']);

        $this->actingAs($user)
            ->post('/user', ['field' => 'change'], ['Idempotency-Key' => 'unique-key-123'])
            ->assertOk()
            ->assertJson(['id' => $user->id, 'field' => 'change']);
    }

    #[Test]
    public function it_replays_an_identical_request_without_executing_it_again(): void
    {
        $user = $this->getUnguardedUser(['field' => 'default']);
        $this->actingAs($user);

        $first = $this->post('/user', ['field' => 'change'], ['Idempotency-Key' => 'same-request']);
        $second = $this->post('/user', ['field' => 'change'], ['Idempotency-Key' => 'same-request']);

        $first->assertOk()->assertHeaderMissing('Idempotency-Replayed');
        $second->assertOk()->assertHeader('Idempotency-Replayed', 'same-request');
        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertSame(1, $this->controllerExecutionCount);
    }

    #[Test]
    public function it_rejects_the_same_key_with_a_different_payload(): void
    {
        $user = $this->getUnguardedUser(['field' => 'default']);
        $this->actingAs($user);
        $headers = ['Idempotency-Key' => 'changed-payload'];

        $this->post('/user', ['field' => 'first'], $headers)->assertOk();
        $this->post('/user', ['field' => 'second'], $headers)
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJson(['class' => 'MismatchedRequestException']);

        $this->assertSame('first', $user->field);
        $this->assertSame(1, $this->controllerExecutionCount);
    }

    #[Test]
    public function it_rejects_the_same_key_with_a_different_query_string(): void
    {
        $headers = ['Idempotency-Key' => 'changed-query'];

        $this->post('/account?mode=one', ['item' => 'book'], $headers)->assertOk();
        $this->post('/account?mode=two', ['item' => 'book'], $headers)
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJson(['class' => 'MismatchedRequestException']);
    }

    #[Test]
    public function query_parameter_order_does_not_change_the_fingerprint(): void
    {
        $headers = ['Idempotency-Key' => 'query-order'];

        $this->post('/account?one=1&two=2', ['item' => 'book'], $headers)->assertOk();
        $this->post('/account?two=2&one=1', ['item' => 'book'], $headers)
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'query-order');
    }

    #[Test]
    public function json_object_key_order_does_not_change_the_fingerprint(): void
    {
        $headers = ['Idempotency-Key' => 'json-order'];

        $this->postJson('/account', ['one' => 1, 'two' => 2], $headers)->assertOk();
        $this->postJson('/account', ['two' => 2, 'one' => 1], $headers)
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'json-order');
    }

    #[Test]
    public function it_rejects_reusing_a_key_with_a_different_method(): void
    {
        $headers = ['Idempotency-Key' => 'changed-method'];
        $payload = ['item' => 'book'];

        $this->post('/account', $payload, $headers)->assertOk();
        $this->put('/account', $payload, $headers)
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJson(['class' => 'MismatchedRequestException']);
    }

    #[Test]
    public function uploaded_file_contents_are_part_of_the_fingerprint(): void
    {
        $headers = ['Idempotency-Key' => 'changed-upload'];

        $this->post('/upload', [
            'document' => UploadedFile::fake()->createWithContent('document.txt', 'first'),
        ], $headers)->assertOk();

        $this->post('/upload', [
            'document' => UploadedFile::fake()->createWithContent('document.txt', 'second'),
        ], $headers)
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJson(['class' => 'MismatchedRequestException']);

        $this->assertSame(1, $this->controllerExecutionCount);
    }

    #[Test]
    public function it_rejects_reusing_a_key_on_a_different_path(): void
    {
        $headers = ['Idempotency-Key' => 'changed-path'];

        $this->post('/account', [], $headers)->assertOk();
        $this->post('/other-account', [], $headers)
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJson(['class' => 'MismatchedRequestException']);
    }

    #[Test]
    public function different_users_can_use_the_same_key(): void
    {
        $headers = ['Idempotency-Key' => 'same-key-for-users'];
        $payload = ['item' => 'book'];

        $firstUser = $this->getUnguardedUser(['id' => 1]);
        $first = $this->actingAs($firstUser)->post('/account', $payload, $headers);

        $secondUser = $this->getUnguardedUser(['id' => 2]);
        $second = $this->actingAs($secondUser)->post('/account', $payload, $headers);

        $first->assertJson(['user_id' => 1]);
        $second->assertJson(['user_id' => 2])->assertHeaderMissing('Idempotency-Replayed');
        $this->assertSame(2, $this->controllerExecutionCount);
    }

    #[Test]
    public function global_scope_shares_keys_between_users(): void
    {
        config(['idempotency.scope' => 'global']);
        $headers = ['Idempotency-Key' => 'global-key'];
        $payload = ['item' => 'book'];

        $firstUser = $this->getUnguardedUser(['id' => 1]);
        $this->actingAs($firstUser)->post('/account', $payload, $headers)->assertJson(['user_id' => 1]);

        $secondUser = $this->getUnguardedUser(['id' => 2]);
        $this->actingAs($secondUser)->post('/account', $payload, $headers)
            ->assertJson(['user_id' => 1])
            ->assertHeader('Idempotency-Replayed', 'global-key');
    }

    #[Test]
    public function ip_scope_is_available_explicitly(): void
    {
        config(['idempotency.scope' => 'ip']);
        $headers = ['Idempotency-Key' => 'ip-key'];

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])
            ->post('/account', [], $headers)
            ->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])
            ->post('/account', [], $headers)
            ->assertOk()
            ->assertHeaderMissing('Idempotency-Replayed');

        $this->assertSame(2, $this->controllerExecutionCount);
    }

    #[Test]
    public function missing_keys_return_bad_request(): void
    {
        $this->post('/account')
            ->assertBadRequest()
            ->assertJson(['class' => 'MissingIdempotencyKeyException']);
    }

    #[Test]
    public function missing_keys_can_be_ignored(): void
    {
        config(['idempotency.ignore_empty_key' => true]);

        $this->post('/account')->assertOk();
    }

    #[Test]
    public function zero_is_a_valid_idempotency_key(): void
    {
        $headers = ['Idempotency-Key' => '0'];

        $this->post('/account', [], $headers)->assertOk();
        $this->post('/account', [], $headers)
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', '0');
    }

    #[Test]
    public function oversized_keys_return_bad_request(): void
    {
        $this->post('/account', [], ['Idempotency-Key' => str_repeat('a', 256)])
            ->assertBadRequest()
            ->assertJson(['class' => 'InvalidIdempotencyKeyException']);
    }

    #[Test]
    public function the_header_name_can_be_configured(): void
    {
        config(['idempotency.idempotency_header' => 'X-Custom-Key']);
        $headers = ['X-Custom-Key' => 'custom-key'];
        $payload = ['item' => 'book'];

        $this->post('/account', $payload, $headers)->assertOk();
        $this->post('/account', $payload, $headers)
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'custom-key');
    }

    #[Test]
    public function non_enforced_methods_pass_through_without_a_key(): void
    {
        config(['idempotency.enforced_verbs' => ['post']]);

        $this->get('/user')->assertOk();
    }

    #[Test]
    public function invalid_configuration_returns_an_internal_server_error(): void
    {
        config(['idempotency.enforced_verbs' => ['POST', 'INVALID']]);

        $this->post('/account', [], ['Idempotency-Key' => 'key'])
            ->assertInternalServerError()
            ->assertJson(['class' => 'InvalidConfigurationException']);
    }

    #[Test]
    public function invalid_in_flight_overrides_return_an_internal_server_error(): void
    {
        $this->app['router']->post('/invalid-in-flight-override', fn () => response('ok'))
            ->middleware('idempotency:invalid');

        $this->post('/invalid-in-flight-override', [], ['Idempotency-Key' => 'key'])
            ->assertInternalServerError()
            ->assertJson(['class' => 'InvalidConfigurationException']);
    }

    #[Test]
    public function completed_duplicates_can_be_rejected(): void
    {
        config(['idempotency.on_duplicate_behaviour' => DuplicateBehaviour::EXCEPTION->value]);
        $headers = ['Idempotency-Key' => 'duplicate-error'];
        $payload = ['item' => 'book'];

        $this->post('/account', $payload, $headers)->assertOk();
        $this->post('/account', $payload, $headers)
            ->assertStatus(Response::HTTP_CONFLICT)
            ->assertJson(['class' => 'DuplicateRequestException']);
    }

    #[Test]
    public function in_flight_duplicates_are_rejected_with_retry_after(): void
    {
        $key = 'in-flight';
        $cacheKey = $this->cacheKey('ip:127.0.0.1', $key);
        $lock = Cache::getStore()->lock($this->lockKey($cacheKey), 30);
        $this->assertTrue($lock->get());

        try {
            $this->post('/account', [], ['Idempotency-Key' => $key])
                ->assertStatus(Response::HTTP_CONFLICT)
                ->assertHeader('Retry-After', '1')
                ->assertJson(['class' => 'RequestInProgressException']);
        } finally {
            $lock->release();
        }
    }

    #[Test]
    public function in_flight_rejections_do_not_fingerprint_the_request(): void
    {
        $this->app->instance(RequestFingerprint::class, new class extends RequestFingerprint
        {
            public function make(Request $request): string
            {
                throw new \RuntimeException('The request should not be fingerprinted.');
            }
        });

        $key = 'lazy-fingerprint';
        $cacheKey = $this->cacheKey('ip:127.0.0.1', $key);
        $lock = Cache::getStore()->lock($this->lockKey($cacheKey), 30);
        $this->assertTrue($lock->get());

        try {
            $this->post('/account', [], ['Idempotency-Key' => $key])
                ->assertConflict()
                ->assertJson(['class' => 'RequestInProgressException']);
        } finally {
            $lock->release();
        }
    }

    #[Test]
    public function wait_override_times_out_with_retry_after(): void
    {
        config(['idempotency.wait_timeout' => 1]);
        $key = 'wait-timeout';
        $cacheKey = $this->cacheKey('ip:127.0.0.1', $key);
        $lock = Cache::getStore()->lock($this->lockKey($cacheKey), 30);
        $this->assertTrue($lock->get());

        $this->app['router']->post('/wait-override', fn () => response('ok'))
            ->middleware('idempotency:wait');

        try {
            $this->post('/wait-override', [], ['Idempotency-Key' => $key])
                ->assertStatus(Response::HTTP_CONFLICT)
                ->assertHeader('Retry-After', '1')
                ->assertJson(['class' => 'RequestInProgressException']);
        } finally {
            $lock->release();
        }
    }

    #[Test]
    public function wait_override_replays_a_response_when_it_becomes_available(): void
    {
        if (! function_exists('pcntl_alarm') || ! function_exists('pcntl_signal')) {
            $this->markTestSkipped('The PCNTL extension is required for this timing test.');
        }

        config(['idempotency.wait_timeout' => 2]);
        $key = 'wait-success';
        $cacheKey = $this->cacheKey('ip:127.0.0.1', $key);
        $lock = Cache::getStore()->lock($this->lockKey($cacheKey), 30);
        $this->assertTrue($lock->get());

        $this->app['router']->post('/wait-override-success', fn () => response('unexpected'))
            ->middleware('idempotency:wait');

        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, function () use ($cacheKey, $key): void {
            Cache::put($cacheKey, [
                'version' => 2,
                'body' => 'completed response',
                'status' => 200,
                'headers' => ['x-completed' => ['yes']],
                'path' => 'wait-override-success',
                'originalKey' => $key,
                'fingerprint' => app(RequestFingerprint::class)->make(Request::create('/wait-override-success', 'POST')),
                'replayable' => true,
            ], 60);
        });
        pcntl_alarm(1);

        try {
            $this->post('/wait-override-success', [], ['Idempotency-Key' => $key])
                ->assertOk()
                ->assertSee('completed response')
                ->assertHeader('X-Completed', 'yes')
                ->assertHeader('Idempotency-Replayed', 'wait-success');
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, SIG_DFL);
            $lock->release();
        }
    }

    #[Test]
    public function locks_are_released_after_downstream_exceptions(): void
    {
        $headers = ['Idempotency-Key' => 'exception-release'];

        $this->post('/throws-once', [], $headers)->assertInternalServerError();
        $cacheKey = $this->cacheKey('ip:127.0.0.1', 'exception-release');
        $lock = Cache::getStore()->lock($this->lockKey($cacheKey), 30);

        $this->assertTrue($lock->get());
        $lock->release();
    }

    #[Test]
    public function cache_entries_use_bounded_hashed_keys(): void
    {
        $key = str_repeat('a', 255);

        $this->post('/account', [], ['Idempotency-Key' => $key])->assertOk();

        $cacheKey = $this->cacheKey('ip:127.0.0.1', $key);
        $this->assertLessThan(250, strlen($cacheKey));
        $this->assertTrue(Cache::has($cacheKey));
        $this->assertIsArray(Cache::get($cacheKey));
    }

    #[Test]
    public function replay_filters_unsafe_headers(): void
    {
        $headers = ['Idempotency-Key' => 'safe-headers'];

        $this->post('/headers', [], $headers)->assertCookie('session-test');
        $replay = $this->post('/headers', [], $headers)
            ->assertOk()
            ->assertHeader('X-Safe', 'kept')
            ->assertHeader('Idempotency-Replayed', 'safe-headers');

        $this->assertSame([], $replay->headers->getCookies());
    }

    #[Test]
    public function unreplayable_responses_leave_a_completion_marker(): void
    {
        $headers = ['Idempotency-Key' => 'stream-response'];

        $this->post('/stream', [], $headers)->assertOk();
        $this->post('/stream', [], $headers)
            ->assertStatus(Response::HTTP_CONFLICT)
            ->assertJson(['class' => 'UnreplayableResponseException']);

        $this->assertSame(1, $this->controllerExecutionCount);
    }

    #[Test]
    public function disabled_response_categories_are_not_cached(): void
    {
        config(['idempotency.cache_statuses.client_error' => false]);
        $headers = ['Idempotency-Key' => 'client-error'];

        $this->post('/client-error', [], $headers)->assertStatus(422);
        $this->post('/client-error', [], $headers)->assertStatus(422);

        $this->assertSame(2, $this->controllerExecutionCount);
    }

    #[Test]
    public function middleware_exposes_request_attributes_downstream(): void
    {
        $this->post('/attributes', [], ['Idempotency-Key' => 'attributes-key'])
            ->assertOk()
            ->assertJson([
                'idempotent' => true,
                'key' => 'attributes-key',
                'scope' => 'ip:127.0.0.1',
            ]);
    }

    #[Test]
    public function corrupt_cached_values_return_an_internal_server_error(): void
    {
        $key = 'corrupt-cache';
        Cache::put($this->cacheKey('ip:127.0.0.1', $key), 'not-a-response');

        $this->post('/account', [], ['Idempotency-Key' => $key])
            ->assertInternalServerError()
            ->assertJson(['class' => 'CorruptedCacheDataException']);
    }

    #[Test]
    public function cached_values_with_missing_fields_are_rejected(): void
    {
        $key = 'missing-fields';
        Cache::put($this->cacheKey('ip:127.0.0.1', $key), ['body' => 'incomplete']);

        $this->post('/account', [], ['Idempotency-Key' => $key])
            ->assertInternalServerError()
            ->assertJson(['class' => 'CorruptedCacheDataException']);
    }

    #[Test]
    public function cached_values_with_invalid_headers_are_rejected(): void
    {
        $key = 'invalid-headers';
        Cache::put($this->cacheKey('ip:127.0.0.1', $key), [
            'version' => 2,
            'body' => 'response',
            'status' => 200,
            'headers' => ['x-invalid' => [42]],
            'path' => 'account',
            'originalKey' => $key,
            'fingerprint' => 'fingerprint',
            'replayable' => true,
        ]);

        $this->post('/account', [], ['Idempotency-Key' => $key])
            ->assertInternalServerError()
            ->assertJson(['class' => 'CorruptedCacheDataException']);
    }

    private function cacheKey(string $scope, string $idempotencyKey): string
    {
        return 'idempotency:response:v2:'.hash('sha256', serialize([$scope, $idempotencyKey]));
    }

    private function lockKey(string $cacheKey): string
    {
        return 'idempotency:lock:v2:'.hash('sha256', $cacheKey);
    }
}
