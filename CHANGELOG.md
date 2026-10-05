# Changelog

## 14.0.0

- Duplicate requests with the same key must now match the method, host, path, query, body, content type, and uploaded files; a mismatch returns `422`.
- Concurrent duplicates return `409` with `Retry-After: 1` by default. Set `on_in_flight_behaviour` to `wait` to keep waiting. `max_lock_wait_time` is ignored; configure `wait_timeout` and `lock_ttl` instead.
- New `scope` (`user`, `ip`, or `global`), `cache_store`, and `cache_statuses` options control key isolation and response caching.
- Replays include the original key in `Idempotency-Replayed`, replacing `Idempotency-Relayed`. Replays omit `Set-Cookie` and other unsafe headers. Streamed and file responses return `409` on a duplicate.
- Cache keys and lock names changed. Version 14 does not read v13 entries or coordinate with v13 locks; drain active requests and allow old entries to expire before upgrading operations that cannot run again.
- `RequestInProgressException` replaces `LockWaitExceededException`; `MismatchedRequestException` replaces `MismatchedPathException`. Package exceptions now carry HTTP status codes.
- Package versions now follow semantic versioning independently of Laravel versions.
