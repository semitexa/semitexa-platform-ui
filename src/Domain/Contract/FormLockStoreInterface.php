<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Contract;

use Semitexa\PlatformUi\Domain\Model\Collaboration\FormLock;
use Semitexa\PlatformUi\Domain\Model\Collaboration\FormLockOutcome;

/**
 * Collaborative Form Data · Phase 2 — the ephemeral lock store for collaborative
 * form documents. Serves both the whole-form lock (`$field === null`) and the
 * per-field lock (`$field` = the field name) on one contract.
 *
 * TTL-driven and non-forcing: {@see acquire()} succeeds only if the slot is
 * free or already held by the same caller. Takeover is implicit — a holder
 * that stops {@see heartbeat()}ing ages out, after which the next acquire
 * wins. Backed by the shared cache (Redis in production) for cross-worker
 * consistency.
 */
interface FormLockStoreInterface
{
    /**
     * Attempt to acquire (or re-affirm) the lock. Returns whether THIS caller
     * holds it now plus the current holder (self when acquired, the incumbent
     * when denied).
     */
    public function acquire(string $scopeKey, ?string $field, string $holderId, string $holderLabel): FormLockOutcome;

    /**
     * Renew the lock's TTL iff this caller still holds it. Returns false when
     * the lock has expired or been taken over (the client must then stop
     * editing / re-acquire).
     */
    public function heartbeat(string $scopeKey, ?string $field, string $holderId): bool;

    /** Release the lock iff this caller holds it (a foreign release is a no-op). */
    public function release(string $scopeKey, ?string $field, string $holderId): void;

    /** The current holder of the lock, or null if free. */
    public function current(string $scopeKey, ?string $field): ?FormLock;
}
