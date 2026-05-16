<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Event\InMemoryUiReplayStore;

/**
 * Unit tests for the worker-local, in-memory replay guard.
 *
 * Worker-locality is intentional — see InMemoryUiReplayStore class docs.
 * These tests pin the documented behaviour:
 *
 *   - first claim with a key succeeds;
 *   - second claim with the same key, before expiry, fails;
 *   - claim succeeds again after the key's TTL elapses;
 *   - distinct keys are independent;
 *   - reset() wipes state (test seam).
 */
final class InMemoryUiReplayStoreTest extends TestCase
{
    #[Test]
    public function first_claim_succeeds(): void
    {
        $store = new InMemoryUiReplayStore();
        self::assertTrue($store->claim('k1', 60));
    }

    #[Test]
    public function second_claim_for_same_key_within_ttl_fails(): void
    {
        $store = new InMemoryUiReplayStore();
        self::assertTrue($store->claim('k1', 60));
        self::assertFalse($store->claim('k1', 60));
    }

    #[Test]
    public function distinct_keys_are_independent(): void
    {
        $store = new InMemoryUiReplayStore();
        self::assertTrue($store->claim('k1', 60));
        self::assertTrue($store->claim('k2', 60));
        self::assertFalse($store->claim('k1', 60));
        self::assertFalse($store->claim('k2', 60));
    }

    #[Test]
    public function reset_clears_all_state(): void
    {
        $store = new InMemoryUiReplayStore();
        $store->claim('k1', 60);
        $store->claim('k2', 60);
        $store->reset();
        self::assertTrue($store->claim('k1', 60));
        self::assertTrue($store->claim('k2', 60));
    }

    #[Test]
    public function reports_non_shared_diagnostic(): void
    {
        $store = new InMemoryUiReplayStore();
        self::assertFalse($store->isShared(), 'In-memory store is worker-local by construction.');
        self::assertSame('in-memory (worker-local)', $store->diagnosticName());
    }

    #[Test]
    public function expired_entries_are_purged_on_subsequent_claim(): void
    {
        $store = new InMemoryUiReplayStore();
        // TTL=1s, then sleep past expiry. The same key must claim again.
        self::assertTrue($store->claim('k1', 1));
        self::assertFalse($store->claim('k1', 1)); // still within ttl
        sleep(2);
        self::assertTrue(
            $store->claim('k1', 1),
            'Replay store must allow a key to be re-claimed after its TTL elapses.',
        );
    }

}
