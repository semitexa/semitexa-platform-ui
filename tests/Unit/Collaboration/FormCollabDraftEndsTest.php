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
use Semitexa\PlatformUi\Application\Service\Collaboration\FormCollaborationDrafts;
use Semitexa\PlatformUi\Application\Service\Collaboration\FormCollaborationEventHandler;
use Semitexa\PlatformUi\Application\Service\Collaboration\FormDocumentProjector;
use Semitexa\PlatformUi\Application\Service\Collaboration\InMemoryFormCollabDraftStore;
use Semitexa\PlatformUi\Domain\Contract\FormCollabDraftStoreInterface;
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
                updated_by TEXT, updated_at TEXT NOT NULL
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
