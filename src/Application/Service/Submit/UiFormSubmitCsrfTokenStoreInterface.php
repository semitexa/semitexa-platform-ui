<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit;

use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitCsrfTokenHandle;

/**
 * Server-side store for FormComponent submit CSRF tokens.
 *
 * Mirrors {@see \Semitexa\PlatformUi\Application\Service\Event\UiReplayStoreInterface}
 * deliberately — same atomic-claim shape, same store-or-fail
 * semantics, same `isShared()` / `diagnosticName()` observability.
 * A submit-time consume is functionally a dispatchId-style replay
 * claim, just keyed by a server-issued token id instead of the
 * caller-minted dispatch id.
 *
 * Flow:
 *
 *   - Render time: form template (when `submitAction` is set) calls
 *     `issue()` to mint a fresh `{id, raw}` pair. The store keeps
 *     only `hash_hmac('sha256', raw, id)` against the id, with a
 *     TTL bounded by the form's signed-ctx lifetime. The raw token
 *     is signed into `cfg.s.t` of the submit ctx and embedded in
 *     the rendered manifest.
 *   - Dispatch time: FormComponent's security policy pulls
 *     `cfg.s.{k, t}` out of the verified context and calls
 *     `consume(k, t)`. The call atomically verifies + removes the
 *     entry. Subsequent submits with the same id return `false`
 *     even within the TTL window.
 *
 * Trust perimeter:
 *
 *   - The raw token never reaches the store as plain text. Storage
 *     uses `hash_hmac` with the token id as the key so leaked cache
 *     entries cannot be replayed even within their TTL.
 *   - `issue()` MUST emit cryptographically random ids and tokens
 *     (32 bytes of entropy minimum) so they are not guessable.
 *   - `consume()` MUST treat verification and removal as a single
 *     atomic check-and-set; concurrent submits with the same token
 *     MUST never both succeed.
 *
 * Implementations:
 *
 *   - {@see CacheBackedUiFormSubmitCsrfTokenStore} — production
 *     default, namespaced through CacheManagerInterface, observable
 *     across all workers that share the cache backend.
 *   - {@see InMemoryUiFormSubmitCsrfTokenStore} — worker-local fallback
 *     for tests / single-worker dev. NOT safe across Swoole workers.
 */
interface UiFormSubmitCsrfTokenStoreInterface
{
    /**
     * Mint a fresh `{id, raw}` pair, persist `hash_hmac(raw, id)`,
     * return the handle. The store MUST guarantee `$id` is
     * collision-resistant within the TTL window (the default 16-hex
     * suffix gives 64 bits of entropy — adequate within the 10-minute
     * default TTL).
     *
     * `$ttlSeconds` is a positive integer; implementations clamp to
     * sensible bounds if needed.
     */
    public function issue(int $ttlSeconds): UiFormSubmitCsrfTokenHandle;

    /**
     * Atomically verify $rawToken against the stored hash for $tokenId
     * and remove the entry on success. Returns:
     *
     *   - `true`  → the entry existed, the hash matched, and the entry
     *               has been removed. Caller may proceed.
     *   - `false` → entry missing, expired, or wrong token. Caller MUST
     *               refuse the dispatch.
     *
     * Implementations MUST NOT distinguish missing-from-mismatch in
     * the return channel — the security policy already produces a
     * uniform `csrf_verification_failed` reason; leaking which arm
     * failed is a side-channel hint to attackers.
     */
    public function consume(string $tokenId, string $rawToken): bool;

    /**
     * True when `issue()` / `consume()` are observable across every
     * worker / process sharing this store. Production deployments
     * require `true`; the dispatcher-level production guard already
     * surfaces non-shared replay-store as a configuration error, and
     * future hardening can mirror the same check here.
     */
    public function isShared(): bool;

    /**
     * Short human-readable identifier — `"cache-backed (driver=redis)"`,
     * `"in-memory (worker-local)"`, etc. Surfaced in diagnostic
     * payloads only; MUST NOT leak secrets, connection strings, or
     * class FQCNs.
     */
    public function diagnosticName(): string;
}
