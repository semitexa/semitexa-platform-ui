<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

/**
 * Replay/idempotency store for UI dispatch attempts.
 *
 * The dispatcher claims a unique key per (signed-ctx-hash, dispatchId)
 * pair. The first claim within the TTL window returns true; every
 * subsequent claim with the same key returns false. This is the only
 * operation the dispatcher needs — no get, no list, no scan.
 *
 * Implementations:
 *   - InMemoryUiReplayStore   — worker-local. Tests + single-worker dev.
 *     NOT safe across multiple Swoole workers or multiple PHP-FPM
 *     processes.
 *   - CacheBackedUiReplayStore — production. Delegates to
 *     CacheManagerInterface so the claim is observable across all
 *     workers sharing the same cache backend (Redis, file-cache, etc.).
 *
 * The interface is intentionally narrow — there is no "purge expired"
 * helper; the underlying store handles its own expiration via TTL.
 *
 * `isShared()` / `diagnosticName()` are introspection methods used by
 * the dispatcher's runtime guard to refuse to invoke handlers in
 * production-like environments when the store cannot deduplicate
 * across workers. They MUST be cheap (no IO).
 */
interface UiReplayStoreInterface
{
    /**
     * Atomically claim a key. Returns true the first time the key is
     * seen (within $ttlSeconds), false on every subsequent call within
     * the window.
     *
     * Implementations MUST treat the operation as a check-and-set so two
     * concurrent dispatch attempts with the same key never both return
     * true. Where the underlying store cannot guarantee true atomicity
     * (e.g. naive get+put on a cache that has no SETNX), the
     * implementation must document the looseness.
     *
     * $ttlSeconds is a positive integer. Callers (the dispatcher) bound
     * this to the signed-context's remaining lifetime so a claim never
     * outlives the ctx that authorised it.
     */
    public function claim(string $key, int $ttlSeconds): bool;

    /**
     * True when claim() is observable across every worker / process
     * sharing this store. Required for production deployments because
     * Swoole / PHP-FPM typically run multiple workers and a per-worker
     * map cannot detect a duplicate that lands on a different worker.
     *
     * The dispatcher refuses to invoke handlers in production-like
     * environments when this returns false.
     */
    public function isShared(): bool;

    /**
     * Short human-readable identifier for the active store, e.g.
     * `"in-memory (worker-local)"` or `"cache-backed (driver=redis)"`.
     * Surfaced in dispatcher diagnostics and in the safe error JSON when
     * the runtime guard rejects a dispatch. MUST NOT leak secrets,
     * connection strings, or PHP class FQCNs.
     */
    public function diagnosticName(): string;
}
