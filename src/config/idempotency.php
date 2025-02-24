<?php

return [
    'cache_duration' => 86400, // 1 day in seconds

    // The header to expect in the request
    'idempotency_header' => 'Idempotency-Key',

    // What to do when we get a duplicate?
    // Options are:
    // - replay: Sends the same response seen previously
    // - exception: Throw an exception
    'on_duplicate_behaviour' => 'replay',

    // Should we carry on if a user has not supplied an idempotency key?
    // Setting this to false will throw a MissingIdempotencyKey exception if no key is supplied.
    'ignore_empty_key' => false,

    // What are the HTTP verbs we should apply idempotency checks to? Any other verbs, we shall
    // not implement the checks, allowing requests to pass through the middleware
    'enforced_verbs' => ['POST', 'PUT', 'PATCH', 'DELETE'],

    // When a race condition happens, we create a cache lock. How long should the lock persist?
    'max_lock_wait_time' => 10,

    // Define custom resolver of per-user identifier. Leave this commented out
    // to default to auth()->user()->id. To support config caching, resolver should
    // be defined as below - class and method pair.
    // 'user_id_resolver' => [ExampleUserIdResolver::class, 'resolveUserId'],
];
