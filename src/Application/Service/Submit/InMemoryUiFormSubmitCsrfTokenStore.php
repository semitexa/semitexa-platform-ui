<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit;

use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitCsrfTokenHandle;

/**
 * Worker-local in-memory CSRF token store.
 *
 * Use case: tests, single-worker dev runs, and as the lazy-default
 * fallback for environments that did not yet wire CacheManagerInterface
 * (the static holder's `getActive()` lazy-defaults to this class).
 * NOT safe across multiple Swoole workers or multiple PHP-FPM
 * processes — a token issued on one worker is invisible to a consume()
 * call landing on another. Production deployments rely on
 * {@see CacheBackedUiFormSubmitCsrfTokenStore} instead.
 *
 * Shape rationale: identical to {@see CacheBackedUiFormSubmitCsrfTokenStore}
 * — same HMAC-only storage, same atomic consume semantics, same
 * uniform false-on-failure (no side-channel between "missing",
 * "expired", and "mismatch").
 */
final class InMemoryUiFormSubmitCsrfTokenStore implements UiFormSubmitCsrfTokenStoreInterface
{
    /** @var array<string, array{hash: string, expiresAt: int}> */
    private array $tokens = [];

    public function issue(int $ttlSeconds): UiFormSubmitCsrfTokenHandle
    {
        $ttl = $ttlSeconds < 1 ? 1 : $ttlSeconds;
        $id = CacheBackedUiFormSubmitCsrfTokenStore::ID_PREFIX . bin2hex(random_bytes(8));
        $raw = bin2hex(random_bytes(16));
        $this->tokens[$id] = [
            'hash' => hash_hmac('sha256', $raw, $id),
            'expiresAt' => time() + $ttl,
        ];
        return new UiFormSubmitCsrfTokenHandle(id: $id, raw: $raw);
    }

    public function consume(string $tokenId, string $rawToken): bool
    {
        $this->purgeExpired();
        if (!isset($this->tokens[$tokenId])) {
            return false;
        }
        $expected = hash_hmac('sha256', $rawToken, $tokenId);
        if (!hash_equals($this->tokens[$tokenId]['hash'], $expected)) {
            return false;
        }
        unset($this->tokens[$tokenId]);
        return true;
    }

    public function isShared(): bool
    {
        return false;
    }

    public function diagnosticName(): string
    {
        return 'in-memory (worker-local)';
    }

    /** Test/reset hook. */
    public function reset(): void
    {
        $this->tokens = [];
    }

    private function purgeExpired(): void
    {
        $now = time();
        foreach ($this->tokens as $id => $entry) {
            if ($entry['expiresAt'] <= $now) {
                unset($this->tokens[$id]);
            }
        }
    }
}
