<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Collaboration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\PlatformUi\Application\Db\MySQL\Repository\FormCollabDraftDbRepository;
use Semitexa\PlatformUi\Application\Service\Collaboration\CacheBackedFormLockStore;
use Semitexa\PlatformUi\Application\Service\Collaboration\CacheBackedFormPresenceStore;
use Semitexa\PlatformUi\Application\Service\Collaboration\FormCollabSaveNotices;
use Semitexa\PlatformUi\Application\Service\Collaboration\FormCollabDraftRetention;
use Semitexa\PlatformUi\Application\Service\Collaboration\FormFieldEditMarks;
use Semitexa\PlatformUi\Application\Service\Collaboration\FormCollaborationDrafts;
use Semitexa\PlatformUi\Application\Service\Collaboration\FormCollaborationEventHandler;
use Semitexa\PlatformUi\Application\Service\Collaboration\FormDocumentProjector;
use Semitexa\PlatformUi\Application\Service\Collaboration\InMemoryFormCollabDraftStore;
use Semitexa\PlatformUi\Domain\Contract\FormCollabDraftStoreInterface;
use Semitexa\PlatformUi\Domain\Contract\FormCollabOrderedDraftStoreInterface;
use Semitexa\PlatformUi\Domain\Model\Collaboration\FormFieldEditOrder;
use Semitexa\PlatformUi\Domain\Contract\FormPresenceStoreInterface;
use Semitexa\PlatformUi\Domain\Model\Collaboration\FormCollaborationMode;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventContext;
use Semitexa\PlatformUi\Tests\Support\ArrayCacheManager;
use Semitexa\PlatformUi\Tests\Support\CollabScopeRecorder;
use Semitexa\PlatformUi\Tests\Support\SettablePresenceStore;

/**
 * A shared draft has to END. It did not: after the app saved the record the
 * draft kept the old edits, and an edit abandoned days ago was written into
 * the inputs of the next person to open the form, whose save stored it.
 *
 * Two ends, each pinned on both stores (the in-memory one and the ORM one on
 * SQLite): discard(), called once the record is persisted, and expiry, when
 * nobody wrote to the draft or was present in its document for the window.
 * Time is the retention's clock, moved by hand: nothing here sleeps.
 */
final class FormCollabDraftEndsTest extends TestCase
{
    private const SCOPE = 'formdoc:property:42';
    private const MINUTE = 60;

    private int $now;

    private SettablePresenceStore $presence;

    private FormCollabDraftRetention $retention;

    protected function setUp(): void
    {
        $this->now = time();
        $this->presence = new SettablePresenceStore(fn (): int => $this->now);
        $this->retention = (new FormCollabDraftRetention())->with($this->presence, 30, fn (): int => $this->now);
    }

    /** @return iterable<string, array{0: string}> */
    public static function stores(): iterable
    {
        yield 'in-memory store' => ['memory'];
        yield 'ORM store' => ['orm'];
    }

    private function store(string $kind): FormCollabDraftStoreInterface
    {
        if ($kind === 'memory') {
            return (new InMemoryFormCollabDraftStore())->withRetention($this->retention);
        }

        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $orm->getAdapter()->execute(
            'CREATE TABLE form_collab_draft (
                id TEXT PRIMARY KEY, tenant_id TEXT, scope_key TEXT NOT NULL,
                values_json TEXT NOT NULL, version INTEGER NOT NULL,
                updated_by TEXT, updated_at TEXT NOT NULL, edit_marks_json TEXT
            )',
        );
        $orm->getAdapter()->execute(
            'CREATE UNIQUE INDEX uniq_form_collab_draft_tenant_scope ON form_collab_draft (tenant_id, scope_key)',
        );

        return (new FormCollabDraftDbRepository())->withOrmManager($orm)->withRetention($this->retention);
    }

    #[Test]
    #[DataProvider('stores')]
    public function discard_clears_the_draft_and_the_next_open_is_seeded_fresh(string $kind): void
    {
        $store = $this->store($kind);
        $store->mergeFields(self::SCOPE, ['price' => '100'], 'alice');
        $store->mergeFields(self::SCOPE, ['price' => '120', 'title' => 'Old flat'], 'alice');

        $store->discard(self::SCOPE);

        self::assertNull($store->load(self::SCOPE), 'the saved edits are no longer an edit in progress');

        $reopened = $store->open(self::SCOPE, ['price' => '150'], 'bob');
        self::assertSame(['price' => '150'], $reopened->values, 'seeded from what the caller read, not from the old edits');
        self::assertSame(1, $reopened->version);
        self::assertSame('bob', $reopened->updatedBy);
    }

    #[Test]
    #[DataProvider('stores')]
    public function edits_of_one_source_are_kept_in_the_order_they_were_made(string $kind): void
    {
        $store = $this->store($kind);
        self::assertInstanceOf(FormCollabOrderedDraftStoreInterface::class, $store);

        self::assertSame(FormFieldEditOrder::Applied, $store->mergeFieldInOrder(self::SCOPE, 'price', '120', 'alice', 'tab-1', 2), 'the first edit creates the draft');
        self::assertSame(FormFieldEditOrder::Superseded, $store->mergeFieldInOrder(self::SCOPE, 'price', '12', 'alice', 'tab-1', 1));
        self::assertSame(FormFieldEditOrder::Repeated, $store->mergeFieldInOrder(self::SCOPE, 'price', '120', 'alice', 'tab-1', 2));
        self::assertSame(['price' => '120'], $store->load(self::SCOPE)?->values);
        self::assertSame(1, $store->load(self::SCOPE)->version, 'a refused edit writes nothing');

        self::assertSame(FormFieldEditOrder::Applied, $store->mergeFieldInOrder(self::SCOPE, 'price', '99', 'alice', 'tab-2', 1), 'another source counts for itself');
        self::assertSame(FormFieldEditOrder::Applied, $store->mergeFieldInOrder(self::SCOPE, 'title', 'Flat', 'alice', 'tab-1', 1), 'and so does another field');
        self::assertSame(FormFieldEditOrder::Applied, $store->mergeFieldInOrder(self::SCOPE, 'price', '125', 'alice', 'tab-1', 3));
        self::assertSame(['price' => '125', 'title' => 'Flat'], $store->load(self::SCOPE)?->values);
    }

    #[Test]
    #[DataProvider('stores')]
    public function a_full_save_and_an_unsequenced_edit_keep_the_order_of_the_edits_before_them(string $kind): void
    {
        $store = $this->store($kind);
        self::assertInstanceOf(FormCollabOrderedDraftStoreInterface::class, $store);
        $store->mergeFieldInOrder(self::SCOPE, 'price', '120', 'alice', 'tab-1', 2);

        $saved = $store->apply(self::SCOPE, ['price' => '130'], 1, 'bob');
        $store->mergeFields(self::SCOPE, ['title' => 'Flat'], 'carol');

        self::assertSame(FormFieldEditOrder::Superseded, $store->mergeFieldInOrder(self::SCOPE, 'price', '12', 'alice', 'tab-1', 1), 'the late edit is still late');
        self::assertSame(['price' => '130', 'title' => 'Flat'], $store->load(self::SCOPE)?->values);
        self::assertSame($saved->epoch, $store->load(self::SCOPE)->epoch);
    }

    #[Test]
    #[DataProvider('stores')]
    public function the_order_of_edits_ends_with_the_draft(string $kind): void
    {
        $store = $this->store($kind);
        self::assertInstanceOf(FormCollabOrderedDraftStoreInterface::class, $store);
        $store->mergeFieldInOrder(self::SCOPE, 'price', '120', 'alice', 'tab-1', 5);

        $store->discard(self::SCOPE);

        self::assertSame(FormFieldEditOrder::Applied, $store->mergeFieldInOrder(self::SCOPE, 'price', '1', 'alice', 'tab-1', 1));

        // An abandoned draft is no draft either.
        $this->presence->lastSeen[self::SCOPE] = $this->now;
        $this->now += 31 * self::MINUTE;
        self::assertSame(FormFieldEditOrder::Applied, $store->mergeFieldInOrder(self::SCOPE, 'price', '2', 'alice', 'tab-1', 1));
    }

    /**
     * An in-memory store whose presence read, the one place it can suspend,
     * runs `$during` on its `$onRead`-th call: what another coroutine does
     * while this one waits for the cache.
     *
     * @param \Closure(InMemoryFormCollabDraftStore): void $during
     * @param-out \Closure(bool): void $arm true starts the count, false ends it; the read is plain otherwise
     */
    private function storeSuspendingAt(int $onRead, \Closure $during, ?\Closure &$arm): InMemoryFormCollabDraftStore
    {
        $store = new InMemoryFormCollabDraftStore();
        $presence = new class ($this->presence) implements FormPresenceStoreInterface {
            public ?\Closure $onRead = null;

            public function __construct(private readonly SettablePresenceStore $inner)
            {
            }

            public function ping(string $scopeKey, string $participantId, string $label, string $role): array
            {
                return $this->inner->ping($scopeKey, $participantId, $label, $role);
            }

            public function leave(string $scopeKey, string $participantId): array
            {
                return $this->inner->leave($scopeKey, $participantId);
            }

            public function roster(string $scopeKey): array
            {
                return $this->inner->roster($scopeKey);
            }

            public function lastSeenAt(string $scopeKey): ?int
            {
                if ($this->onRead !== null) {
                    ($this->onRead)();
                }

                return $this->inner->lastSeenAt($scopeKey);
            }
        };
        $store->withRetention((new FormCollabDraftRetention())->with($presence, 30, fn (): int => $this->now));

        $arm = static function (bool $on) use ($presence, $onRead, $during, $store): void {
            $reads = 0;
            $presence->onRead = !$on ? null : static function () use (&$reads, $presence, $onRead, $during, $store): void {
                if (++$reads === $onRead) {
                    $presence->onRead = null;
                    $during($store);
                }
            };
        };

        return $store;
    }

    /** @return iterable<string, array{0: int}> */
    public static function presenceReads(): iterable
    {
        yield 'during the first presence read' => [1];
        yield 'during a second presence read, if the merge makes one' => [2];
    }

    #[Test]
    #[DataProvider('presenceReads')]
    public function an_edit_stored_while_an_older_one_waits_for_the_presence_read_is_not_overwritten_by_it(int $onRead): void
    {
        // In-memory store. The older edit is being merged; wherever it waits,
        // the newer edit of the same page is stored meanwhile.
        $newerStored = false;
        $store = $this->storeSuspendingAt($onRead, static function (InMemoryFormCollabDraftStore $store) use (&$newerStored): void {
            $newerStored = $store->mergeFieldInOrder(self::SCOPE, 'title', 'ab', 'alice', 'tab-1', 2) === FormFieldEditOrder::Applied;
        }, $arm);
        $store->mergeFields(self::SCOPE, ['title' => ''], 'alice');
        // Old enough to be asked about, and attended: every look at it reads presence.
        $this->now += 31 * self::MINUTE;
        $this->presence->lastSeen[self::SCOPE] = $this->now;

        $arm(true);
        $older = $store->mergeFieldInOrder(self::SCOPE, 'title', 'a', 'alice', 'tab-1', 1);
        $arm(false);

        if ($onRead === 1) {
            self::assertTrue($newerStored, 'the merge reads presence at least once: the case is exercised');
        }
        if ($newerStored) {
            self::assertSame(FormFieldEditOrder::Superseded, $older);
            self::assertSame(['title' => 'ab'], $store->load(self::SCOPE)?->values, 'the newer value stands');
            self::assertSame(FormFieldEditOrder::Repeated, $store->mergeFieldInOrder(self::SCOPE, 'title', 'ab', 'alice', 'tab-1', 2), 'and so does its mark');
        } else {
            self::assertSame(FormFieldEditOrder::Applied, $older, 'nothing was stored meanwhile');
        }
    }

    #[Test]
    public function a_draft_stored_while_an_older_edit_waits_to_hear_the_old_one_expired_is_not_dropped_with_it(): void
    {
        // In-memory store. The draft is abandoned. The older edit asks
        // presence about it and waits; the newer edit of the same page gets
        // the same answer first, drops the draft and starts a new one. The
        // answer the older edit then receives is about the draft that is gone.
        $newerStored = false;
        $store = $this->storeSuspendingAt(1, static function (InMemoryFormCollabDraftStore $store) use (&$newerStored): void {
            $newerStored = $store->mergeFieldInOrder(self::SCOPE, 'title', 'ab', 'alice', 'tab-1', 2) === FormFieldEditOrder::Applied;
        }, $arm);
        $store->mergeFields(self::SCOPE, ['title' => 'abandoned', 'notes' => 'abandoned'], 'bob');
        $abandoned = $store->load(self::SCOPE)?->epoch;
        $this->presence->lastSeen[self::SCOPE] = $this->now;
        $this->now += 31 * self::MINUTE;

        $arm(true);
        $older = $store->mergeFieldInOrder(self::SCOPE, 'title', 'a', 'alice', 'tab-1', 1);
        $arm(false);

        self::assertTrue($newerStored, 'the newer edit was stored while the older one waited');
        self::assertSame(FormFieldEditOrder::Superseded, $older, "judged against the new draft's marks");
        // The new draft was written just now by the wall clock; the test's is ahead.
        $this->now = time();
        $stored = $store->load(self::SCOPE);
        self::assertSame(['title' => 'ab'], $stored?->values, 'the newer value, in the draft that replaced the abandoned one');
        self::assertNotSame($abandoned, $stored->epoch);
        self::assertSame(FormFieldEditOrder::Repeated, $store->mergeFieldInOrder(self::SCOPE, 'title', 'ab', 'alice', 'tab-1', 2), 'and its mark');
    }

    #[Test]
    #[DataProvider('presenceReads')]
    public function a_draft_that_expires_while_an_edit_is_merged_does_not_hand_its_marks_to_the_next_draft(int $onRead): void
    {
        // In-memory store. The last editor's heartbeat ages out while the
        // edit waits: the draft it was checked against is dropped and the
        // edit starts a new one, which has stored nothing from anybody.
        $store = $this->storeSuspendingAt($onRead, function (): void {
            $this->presence->lastSeen[self::SCOPE] = $this->now - 120 * self::MINUTE;
        }, $arm);
        $store->mergeFieldInOrder(self::SCOPE, 'title', 'old draft', 'bob', 'tab-2', 9);
        $before = $store->load(self::SCOPE)?->epoch;
        $this->now += 31 * self::MINUTE;
        $this->presence->lastSeen[self::SCOPE] = $this->now;

        $arm(true);
        self::assertSame(FormFieldEditOrder::Applied, $store->mergeFieldInOrder(self::SCOPE, 'title', 'mine', 'alice', 'tab-1', 1));
        $arm(false);
        // Attended again, so the draft the edit wrote is there to look at.
        $this->presence->lastSeen[self::SCOPE] = $this->now;
        $after = $store->load(self::SCOPE)?->epoch;

        $late = $store->mergeFieldInOrder(self::SCOPE, 'title', 'bob, counting from one', 'bob', 'tab-2', 1);
        if ($after !== $before) {
            self::assertSame(FormFieldEditOrder::Applied, $late, 'a replacement draft starts with no marks');
        } else {
            self::assertSame(FormFieldEditOrder::Superseded, $late, 'the same draft still knows the edit for an old one');
        }
        if ($onRead === 1) {
            self::assertNotSame($before, $after, 'the merge reads presence at least once: the case is exercised');
        }
    }

    #[Test]
    public function a_field_remembers_a_bounded_number_of_sources_and_forgets_the_one_written_longest_ago(): void
    {
        $marks = [];
        for ($n = 1; $n <= FormFieldEditMarks::SOURCES_PER_FIELD; $n++) {
            [, $marks] = FormFieldEditMarks::place($marks, 'price', 'tab-' . $n, 10);
        }
        // tab-1 writes again: no longer the oldest.
        [, $marks] = FormFieldEditMarks::place($marks, 'price', 'tab-1', 11);
        [$order, $marks] = FormFieldEditMarks::place($marks, 'price', 'one more', 1);

        self::assertSame(FormFieldEditOrder::Applied, $order);
        self::assertCount(FormFieldEditMarks::SOURCES_PER_FIELD, $marks['price']);
        self::assertSame(FormFieldEditOrder::Superseded, FormFieldEditMarks::place($marks, 'price', 'tab-1', 10)[0], 'kept: it wrote last');
        self::assertSame(FormFieldEditOrder::Applied, FormFieldEditMarks::place($marks, 'price', 'tab-2', 1)[0], 'forgotten: ordered by arrival again');
        self::assertSame($marks, FormFieldEditMarks::sanitize(json_decode((string) json_encode($marks), true)), 'and they survive the row');
        self::assertSame([], FormFieldEditMarks::sanitize(['price' => ['tab' => '3', 'x' => -1], 'title' => 'no']));
    }

    #[Test]
    #[DataProvider('stores')]
    public function the_draft_after_a_discard_is_told_apart_from_the_one_before_at_the_same_version(string $kind): void
    {
        $store = $this->store($kind);
        $first = $store->open(self::SCOPE, ['price' => '1'], 'alice');
        $edited = $store->mergeFields(self::SCOPE, ['price' => '2'], 'alice');
        self::assertNotSame('', $first->epoch);
        self::assertSame($first->epoch, $edited->epoch, 'one draft, one epoch, whatever its version');
        self::assertSame($first->epoch, $store->load(self::SCOPE)?->epoch);

        $store->discard(self::SCOPE);
        $second = $store->open(self::SCOPE, ['price' => '3'], 'bob');

        self::assertSame($first->version, $second->version, 'the version restarts, so it cannot tell them apart');
        self::assertNotSame($first->epoch, $second->epoch);
        self::assertSame(
            $second->epoch,
            (new FormDocumentProjector())->withStores($store, $this->presence)->project(self::SCOPE, FormCollaborationMode::Optimistic)->toEnvelope()['data']['epoch'],
            'and the editors are sent it',
        );
    }

    #[Test]
    #[DataProvider('stores')]
    public function a_versioned_write_names_its_draft_and_is_refused_on_another_at_the_same_version(string $kind): void
    {
        $store = $this->store($kind);
        $old = $store->apply(self::SCOPE, ['price' => '1'], 0, 'alice', '');
        $store->discard(self::SCOPE);
        $new = $store->apply(self::SCOPE, ['price' => '2'], 0, 'carol', '');
        self::assertSame($old->version, $new->version);

        try {
            $store->apply(self::SCOPE, ['price' => 'stale'], 1, 'alice', $old->epoch);
            self::fail('expected a conflict');
        } catch (\Semitexa\PlatformUi\Domain\Exception\FormDraftVersionConflictException $e) {
            self::assertSame(1, $e->getErrorContext()['currentVersion']);
        }
        self::assertSame(['price' => '2'], $store->load(self::SCOPE)?->values);

        try {
            $store->apply(self::SCOPE, ['price' => 'stale'], 1, 'alice', '');
            self::fail('expected a conflict: "no draft" is not this draft either');
        } catch (\Semitexa\PlatformUi\Domain\Exception\FormDraftVersionConflictException) {
            self::assertSame(['price' => '2'], $store->load(self::SCOPE)->values);
        }

        self::assertSame(2, $store->apply(self::SCOPE, ['price' => '3'], 1, 'carol', $new->epoch)->version);
        self::assertSame(3, $store->apply(self::SCOPE, ['price' => '4'], 2, 'carol')->version, 'no epoch given: the version alone, as before');
    }

    #[Test]
    #[DataProvider('stores')]
    public function discard_of_a_draft_that_is_not_there_is_no_error(string $kind): void
    {
        $store = $this->store($kind);

        $store->discard(self::SCOPE);
        $store->discard(self::SCOPE);

        self::assertNull($store->load(self::SCOPE));
    }

    #[Test]
    #[DataProvider('stores')]
    public function a_field_edit_after_discard_starts_from_nothing(string $kind): void
    {
        $store = $this->store($kind);
        $store->mergeFields(self::SCOPE, ['price' => '120', 'title' => 'Old flat'], 'alice');
        $store->discard(self::SCOPE);

        $state = $store->mergeFields(self::SCOPE, ['title' => 'New flat'], 'bob');

        self::assertSame(['title' => 'New flat'], $state->values, 'the discarded price must not ride along');
        self::assertSame(1, $state->version);
    }

    #[Test]
    #[DataProvider('stores')]
    public function an_abandoned_draft_is_dropped_by_the_next_load(string $kind): void
    {
        $store = $this->store($kind);
        $store->mergeFields(self::SCOPE, ['price' => '1'], 'alice');
        $this->presence->lastSeen[self::SCOPE] = $this->now;

        $this->now += 31 * self::MINUTE;

        self::assertNull($store->load(self::SCOPE));
        // Gone for good, not merely hidden while the clock says so.
        $this->now -= 31 * self::MINUTE;
        self::assertNull($store->load(self::SCOPE));
    }

    #[Test]
    #[DataProvider('stores')]
    public function an_abandoned_draft_is_dropped_by_the_next_open_which_seeds_afresh(string $kind): void
    {
        $store = $this->store($kind);
        $store->mergeFields(self::SCOPE, ['price' => '1', 'title' => 'typed and left'], 'alice');

        $this->now += 3 * 24 * 60 * self::MINUTE;

        $opened = $store->open(self::SCOPE, ['price' => '200'], 'bob');
        self::assertSame(['price' => '200'], $opened->values);
        self::assertSame(1, $opened->version);
    }

    #[Test]
    #[DataProvider('stores')]
    public function an_edit_on_an_abandoned_draft_does_not_merge_into_it(string $kind): void
    {
        $store = $this->store($kind);
        $store->mergeFields(self::SCOPE, ['price' => '1', 'title' => 'typed and left'], 'alice');
        $this->presence->lastSeen[self::SCOPE] = $this->now;

        $this->now += 31 * self::MINUTE;

        $state = $store->mergeFields(self::SCOPE, ['title' => 'Bob today'], 'bob');
        self::assertSame(['title' => 'Bob today'], $state->values, "Alice's abandoned price must not come back under Bob's edit");
    }

    #[Test]
    #[DataProvider('stores')]
    public function a_draft_someone_is_present_in_is_kept_however_old_its_last_write(string $kind): void
    {
        $store = $this->store($kind);
        $store->mergeFields(self::SCOPE, ['price' => '1'], 'alice');

        // Alice reads the form for two hours and types nothing; her tab
        // heartbeats. The last one was 20 s ago.
        $this->now += 120 * self::MINUTE;
        $this->presence->lastSeen[self::SCOPE] = $this->now - 20;

        self::assertSame(['price' => '1'], $store->load(self::SCOPE)?->values);
        self::assertSame(2, $store->mergeFields(self::SCOPE, ['title' => 't'], 'alice')->version, 'and it is still the same draft');
    }

    #[Test]
    #[DataProvider('stores')]
    public function a_draft_whose_editor_left_a_few_minutes_ago_is_kept(string $kind): void
    {
        $store = $this->store($kind);
        $store->mergeFields(self::SCOPE, ['price' => '1'], 'alice');

        // Written two hours ago, last heartbeat 29 minutes ago (a closed lid).
        $this->now += 120 * self::MINUTE;
        $this->presence->lastSeen[self::SCOPE] = $this->now - 29 * self::MINUTE;

        self::assertNotNull($store->load(self::SCOPE));
    }

    #[Test]
    #[DataProvider('stores')]
    public function a_draft_written_within_the_window_is_kept_with_nobody_present(string $kind): void
    {
        $store = $this->store($kind);
        $store->mergeFields(self::SCOPE, ['price' => '1'], 'alice');

        $this->now += 29 * self::MINUTE;

        self::assertNotNull($store->load(self::SCOPE));
        self::assertSame([], $this->presence->calls, 'a draft in use costs no presence read');
    }

    #[Test]
    #[DataProvider('stores')]
    public function a_window_of_zero_turns_expiry_off(string $kind): void
    {
        $this->retention->with($this->presence, 0, fn (): int => $this->now);
        $store = $this->store($kind);
        $store->mergeFields(self::SCOPE, ['price' => '1'], 'alice');

        $this->now += 365 * 24 * 60 * self::MINUTE;

        self::assertNotNull($store->load(self::SCOPE));
    }

    #[Test]
    public function the_window_is_thirty_minutes_unless_configured_and_never_beyond_what_presence_remembers(): void
    {
        self::assertSame(30 * self::MINUTE, (new FormCollabDraftRetention())->idleSeconds());
        self::assertSame(5 * self::MINUTE, (new FormCollabDraftRetention())->with($this->presence, 5)->idleSeconds());
        self::assertSame(24 * 60 * self::MINUTE, (new FormCollabDraftRetention())->with($this->presence, 1_000_000)->idleSeconds());
        self::assertSame(0, (new FormCollabDraftRetention())->with($this->presence, -3)->idleSeconds());
    }

    #[Test]
    #[DataProvider('stores')]
    public function a_cache_that_misses_everything_does_not_cost_an_attended_draft(string $kind): void
    {
        // Alice is typing nothing but is there; Redis blips (an outage is
        // swallowed into a miss) or was flushed, so her heartbeat mark reads
        // as absent while Bob's feed projects. "No mark" is not "nobody":
        // the draft she is looking at stays.
        $cache = new ArrayCacheManager();
        $presence = (new CacheBackedFormPresenceStore())->withCacheManager($cache);
        $this->retention->with($presence, 30, fn (): int => $this->now);
        $store = $this->store($kind);
        $store->mergeFields(self::SCOPE, ['price' => '1'], 'alice');
        $presence->ping(self::SCOPE, 'alice', 'Alice', 'editor');
        $this->now += 45 * self::MINUTE;
        $cache->unreachable = true;
        $presence->withCacheManager($cache);
        self::assertNull($presence->lastSeenAt(self::SCOPE), 'the store cannot say');

        self::assertSame(['price' => '1'], $store->load(self::SCOPE)?->values);
        self::assertSame(2, $store->mergeFields(self::SCOPE, ['title' => 't'], 'alice')->version, 'the same draft, not a new one');
    }

    #[Test]
    #[DataProvider('stores')]
    public function with_presence_unknown_only_a_draft_older_than_any_mark_can_be_is_dropped(string $kind): void
    {
        $cache = new ArrayCacheManager();
        $cache->unreachable = true;
        $this->retention->with((new CacheBackedFormPresenceStore())->withCacheManager($cache), 30, fn (): int => $this->now);
        $store = $this->store($kind);
        $store->mergeFields(self::SCOPE, ['price' => '1'], 'alice');

        $this->now += FormPresenceStoreInterface::LAST_SEEN_MEMORY_SECONDS - self::MINUTE;
        self::assertNotNull($store->load(self::SCOPE), 'a mark could still exist: not known, kept');

        $this->now += 2 * self::MINUTE;
        self::assertNull($store->load(self::SCOPE), 'no mark lives this long: abandoned for certain');
    }

    #[Test]
    public function both_stores_get_the_retention_from_the_container(): void
    {
        // An app that binds the in-memory store (one worker, dev) must not get
        // drafts that never expire where the ORM store's do.
        foreach ([FormCollabDraftDbRepository::class, InMemoryFormCollabDraftStore::class] as $store) {
            $property = new \ReflectionProperty($store, 'retention');
            self::assertSame(FormCollabDraftRetention::class, (string) $property->getType(), $store);
            self::assertCount(1, $property->getAttributes(\Semitexa\Core\Attribute\InjectAsReadonly::class), $store);
        }
    }

    #[Test]
    public function a_presence_store_that_throws_keeps_the_draft_too(): void
    {
        $failing = new class implements FormPresenceStoreInterface {
            public function ping(string $scopeKey, string $participantId, string $label, string $role): array
            {
                return [];
            }

            public function leave(string $scopeKey, string $participantId): array
            {
                return [];
            }

            public function roster(string $scopeKey): array
            {
                return [];
            }

            public function lastSeenAt(string $scopeKey): ?int
            {
                throw new \RuntimeException('cache offline');
            }
        };

        self::assertFalse(
            (new FormCollabDraftRetention())->with($failing, 30, fn (): int => $this->now)->isAbandoned(self::SCOPE, $this->now - 3600),
        );
    }

    #[Test]
    public function the_first_heartbeat_of_a_newcomer_does_not_revive_an_abandoned_draft(): void
    {
        // A page pings and opens its feed at the same moment. When the ping
        // landed first it made the document "attended", and the feed then
        // served the days-old draft into the newcomer's inputs.
        $store = (new InMemoryFormCollabDraftStore())->withRetention($this->retention);
        $store->mergeFields(self::SCOPE, ['price' => '1'], 'alice');
        $this->presence->lastSeen[self::SCOPE] = $this->now;
        $this->now += 3 * 24 * 60 * self::MINUTE;

        $scopes = new CollabScopeRecorder();
        $handler = (new FormCollaborationEventHandler())->withCollaborationDeps(
            $store,
            $this->presence,
            (new CacheBackedFormLockStore())->withCacheManager(new ArrayCacheManager()),
            $scopes,
        );
        $handler->handle((object) [], new UiEventContext(
            eventId: 'evt',
            correlationId: 'cor',
            semanticEvent: 'presence.ping',
            signedClaims: ['i' => 'bob-tab', 'cfg' => ['scope' => self::SCOPE, 'mode' => 'shared', 'fields' => ['price']]],
            request: [],
        ));

        self::assertSame($this->now, $this->presence->lastSeen[self::SCOPE], 'the ping was recorded');
        $snapshot = (new FormDocumentProjector())->withStores($store, $this->presence)->project(self::SCOPE, FormCollaborationMode::Shared);
        self::assertSame([], $snapshot->values);
        self::assertSame(0, $snapshot->version);
    }

    private function drafts(FormCollabDraftStoreInterface $store, CollabScopeRecorder $scopes, ?FormCollabSaveNotices $notices = null): FormCollaborationDrafts
    {
        return (new FormCollaborationDrafts())->with(
            $store,
            $scopes,
            $notices ?? (new FormCollabSaveNotices())->withCacheManager(new ArrayCacheManager()),
        );
    }

    #[Test]
    #[DataProvider('stores')]
    public function the_app_ends_the_draft_after_its_save_and_the_open_editors_are_told(string $kind): void
    {
        $store = $this->store($kind);
        $store->mergeFields(self::SCOPE, ['price' => '120', 'title' => 'Old flat'], 'alice');
        $scopes = new CollabScopeRecorder();
        $notices = (new FormCollabSaveNotices())->withCacheManager(new ArrayCacheManager());

        $this->drafts($store, $scopes, $notices)->discardForRecord('property', '42');

        self::assertNull($store->load(self::SCOPE));
        self::assertSame([self::SCOPE], $scopes->touched, 'one touch of the document scope: the feeds of the open editors re-run');
        $notice = $notices->current(self::SCOPE);
        self::assertNotNull($notice);
        self::assertNull($notice['values'], 'no values given: the editors are asked, not re-based');
    }

    #[Test]
    #[DataProvider('stores')]
    public function the_persisted_values_go_to_the_open_editors_once_and_leave_no_draft_behind(string $kind): void
    {
        // Seeding a new draft with them left a copy of the record that lived
        // for the whole window and overwrote the inputs of whoever opened the
        // form after the record changed again elsewhere.
        $store = $this->store($kind);
        $store->mergeFields(self::SCOPE, ['price' => '120', 'title' => 'Old flat', 'notes' => 'never saved'], 'alice');
        $scopes = new CollabScopeRecorder();
        $notices = (new FormCollabSaveNotices())->withCacheManager(new ArrayCacheManager());
        $before = time();

        $this->drafts($store, $scopes, $notices)->discard(self::SCOPE, ['price' => '125', 'title' => 'Old flat']);

        self::assertNull($store->load(self::SCOPE), 'no draft holds a copy of the record');
        $envelope = (new FormDocumentProjector())->withStores($store, $this->presence, null, $notices)
            ->project(self::SCOPE, FormCollaborationMode::Shared)->toEnvelope();
        self::assertSame([], $envelope['data']['values']);
        self::assertSame(0, $envelope['data']['version']);
        $saved = $envelope['meta']['saved'];
        self::assertIsArray($saved);
        self::assertSame(['price' => '125', 'title' => 'Old flat'], $saved['values']);
        self::assertGreaterThanOrEqual($before, $saved['at']);
        self::assertSame([self::SCOPE], $scopes->touched);
    }

    #[Test]
    public function every_save_is_a_notice_of_its_own_even_with_no_draft_on_either_side(): void
    {
        // A draft created and discarded between two frames leaves "no draft"
        // before and after: the notice id is what changed.
        $store = new InMemoryFormCollabDraftStore();
        $notices = (new FormCollabSaveNotices())->withCacheManager(new ArrayCacheManager());
        $drafts = $this->drafts($store, new CollabScopeRecorder(), $notices);
        $projector = (new FormDocumentProjector())->withStores($store, $this->presence, null, $notices);

        $before = $projector->project(self::SCOPE, FormCollaborationMode::Optimistic)->toEnvelope();
        $store->apply(self::SCOPE, ['price' => '1'], 0, 'alice');
        $drafts->discard(self::SCOPE);
        $after = $projector->project(self::SCOPE, FormCollaborationMode::Optimistic)->toEnvelope();
        $drafts->discard(self::SCOPE);
        $again = $projector->project(self::SCOPE, FormCollaborationMode::Optimistic)->toEnvelope();

        self::assertSame($before['data'], $after['data'], 'the draft part of the frame cannot tell');
        self::assertNull($before['meta']['saved']);
        self::assertIsArray($after['meta']['saved']);
        self::assertIsArray($again['meta']['saved']);
        self::assertNotSame($after['meta']['saved']['id'], $again['meta']['saved']['id']);
    }

    #[Test]
    public function a_cache_that_lost_the_notice_still_ends_the_draft(): void
    {
        $cache = new ArrayCacheManager();
        $cache->unreachable = true;
        $store = new InMemoryFormCollabDraftStore();
        $store->mergeFields(self::SCOPE, ['price' => '120'], 'alice');
        $scopes = new CollabScopeRecorder();

        $this->drafts($store, $scopes, (new FormCollabSaveNotices())->withCacheManager($cache))->discard(self::SCOPE, ['price' => '125']);

        self::assertNull($store->load(self::SCOPE));
        self::assertSame([self::SCOPE], $scopes->touched);
    }

    #[Test]
    public function a_scope_that_is_not_a_document_is_refused_before_anything_is_deleted(): void
    {
        $scopes = new CollabScopeRecorder();
        $store = new InMemoryFormCollabDraftStore();
        $store->mergeFields(self::SCOPE, ['price' => '120'], 'alice');

        try {
            $this->drafts($store, $scopes)->discard('orders');
            self::fail('expected a refusal');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $scopes->touched);
            self::assertNotNull($store->load(self::SCOPE));
        }
    }
}
