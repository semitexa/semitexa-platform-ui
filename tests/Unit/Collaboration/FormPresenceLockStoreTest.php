<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Collaboration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Collaboration\CacheBackedFormLockStore;
use Semitexa\PlatformUi\Application\Service\Collaboration\CacheBackedFormPresenceStore;
use Semitexa\PlatformUi\Tests\Support\ArrayCacheManager;

/**
 * Collaborative Form Data · Phase 2 — the ephemeral presence + lock stores,
 * driven through an array-backed CacheManager fake. Pins roster upsert/leave,
 * lock acquire/deny/re-affirm/heartbeat/release, and whole-form-vs-field slot
 * isolation. TTL expiry itself is a cache-driver concern (Redis); these tests
 * pin the store logic.
 */
final class FormPresenceLockStoreTest extends TestCase
{
    private const SCOPE = 'formdoc:article:42';

    // ---- presence ----------------------------------------------------------

    #[Test]
    public function ping_adds_a_participant_to_the_roster(): void
    {
        $store = (new CacheBackedFormPresenceStore())->withCacheManager(new ArrayCacheManager());

        $roster = $store->ping(self::SCOPE, 'p1', 'Alice', 'editor');

        self::assertCount(1, $roster);
        self::assertSame('p1', $roster[0]->participantId);
        self::assertSame('Alice', $roster[0]->label);
        self::assertSame('editor', $roster[0]->role);
    }

    #[Test]
    public function two_participants_coexist_and_leave_removes_one(): void
    {
        $store = (new CacheBackedFormPresenceStore())->withCacheManager(new ArrayCacheManager());
        $store->ping(self::SCOPE, 'p1', 'Alice', 'editor');
        $store->ping(self::SCOPE, 'p2', 'Bob', 'viewer');

        self::assertCount(2, $store->roster(self::SCOPE));

        $roster = $store->leave(self::SCOPE, 'p1');
        self::assertCount(1, $roster);
        self::assertSame('p2', $roster[0]->participantId);
    }

    #[Test]
    public function presence_is_isolated_per_scope(): void
    {
        $store = (new CacheBackedFormPresenceStore())->withCacheManager(new ArrayCacheManager());
        $store->ping(self::SCOPE, 'p1', 'Alice', 'editor');

        self::assertCount(0, $store->roster('formdoc:article:99'));
    }

    // ---- lock --------------------------------------------------------------

    #[Test]
    public function first_acquire_wins_and_a_second_holder_is_denied(): void
    {
        $store = (new CacheBackedFormLockStore())->withCacheManager(new ArrayCacheManager());

        $a = $store->acquire(self::SCOPE, null, 'h1', 'Alice');
        self::assertTrue($a->acquired);
        self::assertSame('h1', $a->holder->holderId);

        $b = $store->acquire(self::SCOPE, null, 'h2', 'Bob');
        self::assertFalse($b->acquired);
        self::assertTrue($b->heldByOther());
        self::assertSame('h1', $b->holder->holderId); // reports the incumbent
        self::assertSame('Alice', $b->holder->holderLabel);
    }

    #[Test]
    public function the_same_holder_can_reaffirm_and_acquired_at_is_preserved(): void
    {
        $store = (new CacheBackedFormLockStore())->withCacheManager(new ArrayCacheManager());
        $first = $store->acquire(self::SCOPE, null, 'h1', 'Alice');

        $again = $store->acquire(self::SCOPE, null, 'h1', 'Alice');
        self::assertTrue($again->acquired);
        self::assertSame($first->holder->acquiredAt, $again->holder->acquiredAt);
    }

    #[Test]
    public function heartbeat_only_succeeds_for_the_current_holder(): void
    {
        $store = (new CacheBackedFormLockStore())->withCacheManager(new ArrayCacheManager());
        $store->acquire(self::SCOPE, null, 'h1', 'Alice');

        self::assertTrue($store->heartbeat(self::SCOPE, null, 'h1'));
        self::assertFalse($store->heartbeat(self::SCOPE, null, 'h2'));
    }

    #[Test]
    public function release_frees_the_lock_for_the_next_acquirer(): void
    {
        $store = (new CacheBackedFormLockStore())->withCacheManager(new ArrayCacheManager());
        $store->acquire(self::SCOPE, null, 'h1', 'Alice');

        // A foreign release is a no-op.
        $store->release(self::SCOPE, null, 'h2');
        self::assertFalse($store->acquire(self::SCOPE, null, 'h2', 'Bob')->acquired);

        // The holder's release frees it.
        $store->release(self::SCOPE, null, 'h1');
        self::assertNull($store->current(self::SCOPE, null));
        self::assertTrue($store->acquire(self::SCOPE, null, 'h2', 'Bob')->acquired);
    }

    #[Test]
    public function whole_form_and_field_locks_are_independent_slots(): void
    {
        $store = (new CacheBackedFormLockStore())->withCacheManager(new ArrayCacheManager());

        self::assertTrue($store->acquire(self::SCOPE, null, 'h1', 'Alice')->acquired);
        // A field lock on the same scope is a different slot — Bob can hold it.
        self::assertTrue($store->acquire(self::SCOPE, 'title', 'h2', 'Bob')->acquired);
        // But a second field lock on the same field is denied.
        self::assertFalse($store->acquire(self::SCOPE, 'title', 'h3', 'Carol')->acquired);
        // A different field is free.
        self::assertTrue($store->acquire(self::SCOPE, 'body', 'h3', 'Carol')->acquired);
    }
}
