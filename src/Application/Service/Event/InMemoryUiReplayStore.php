<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

/**
 * Worker-local in-memory replay store.
 *
 * Use case: tests, single-worker dev runs, and as a deliberate fallback
 * when no shared cache is wired. NOT safe across multiple Swoole workers
 * or multiple PHP-FPM processes — a duplicate dispatch attempt routed to
 * a different worker would land on a different in-memory map and would
 * NOT be detected.
 *
 * For production, wire `CacheBackedUiReplayStore` (or any
 * UiReplayStoreInterface backed by a process-shared store like Redis).
 */
final class InMemoryUiReplayStore implements UiReplayStoreInterface
{
    /** @var array<string, int> key → unix timestamp at which the claim expires */
    private array $claims = [];

    public function claim(string $key, int $ttlSeconds): bool
    {
        $this->purgeExpired();

        if (isset($this->claims[$key])) {
            return false;
        }

        $ttl = $ttlSeconds < 1 ? 1 : $ttlSeconds;
        $this->claims[$key] = time() + $ttl;
        return true;
    }

    public function isShared(): bool
    {
        // Per-worker map by construction. The dispatcher's runtime
        // guard refuses production dispatches when this returns false.
        return false;
    }

    public function diagnosticName(): string
    {
        return 'in-memory (worker-local)';
    }

    /** Test/reset hook. */
    public function reset(): void
    {
        $this->claims = [];
    }

    private function purgeExpired(): void
    {
        $now = time();
        foreach ($this->claims as $key => $expiresAt) {
            if ($expiresAt <= $now) {
                unset($this->claims[$key]);
            }
        }
    }
}
