<?php

return [
    'cache_duration' => env('IDEMPOTENCY_CACHE_DURATION', 86400),

    'cache_store' => env('IDEMPOTENCY_CACHE_STORE'),

    'idempotency_header' => env('IDEMPOTENCY_HEADER', 'Idempotency-Key'),

    'ignore_empty_key' => env('IDEMPOTENCY_IGNORE_EMPTY_KEY', false),

    'enforced_verbs' => ['POST', 'PUT', 'PATCH', 'DELETE'],

    'scope' => env('IDEMPOTENCY_SCOPE', 'user'),

    'on_duplicate_behaviour' => env('IDEMPOTENCY_ON_DUPLICATE', 'replay'),

    'on_in_flight_behaviour' => env('IDEMPOTENCY_ON_IN_FLIGHT', 'reject'),

    'lock_ttl' => env('IDEMPOTENCY_LOCK_TTL', 30),

    'wait_timeout' => env('IDEMPOTENCY_WAIT_TIMEOUT', 2),

    'cache_statuses' => [
        'informational' => true,
        'success' => true,
        'redirection' => true,
        'client_error' => true,
        'server_error' => true,
    ],

    // 'user_id_resolver' => [ExampleUserIdResolver::class, 'resolveUserId'],
];
