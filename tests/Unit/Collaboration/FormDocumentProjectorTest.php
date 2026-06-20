<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Collaboration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Collaboration\CacheBackedFormLockStore;
use Semitexa\PlatformUi\Application\Service\Collaboration\CacheBackedFormPresenceStore;
use Semitexa\PlatformUi\Application\Service\Collaboration\FormDocumentProjector;
use Semitexa\PlatformUi\Application\Service\Collaboration\InMemoryFormCollabDraftStore;
use Semitexa\PlatformUi\Domain\Contract\FormCollabDraftStoreInterface;
use Semitexa\PlatformUi\Domain\Contract\FormLockStoreInterface;
use Semitexa\PlatformUi\Domain\Contract\FormPresenceStoreInterface;
use Semitexa\PlatformUi\Domain\Model\Collaboration\FormCollaborationMode;
use Semitexa\PlatformUi\Tests\Support\ArrayCacheManager;

/**
 * Collaborative Form Data · Phase 3 (Shared mode) — the READ projector that
 * renders one document's live shared state for the held-open feed: the merged
 * draft (values + version + last-writer origin) plus the presence roster from
 * the presence store. Driven with the same in-memory / cache-backed store fakes
 * as the inbound handler test, so the read and write halves meet on real stores.
 */
final class FormDocumentProjectorTest extends TestCase
{
    private const SCOPE = 'formdoc:article:42';

    private FormCollabDraftStoreInterface $draft;
    private FormPresenceStoreInterface $presence;
    private FormLockStoreInterface $locks;
    private FormDocumentProjector $projector;

    protected function setUp(): void
    {
        $this->draft = new InMemoryFormCollabDraftStore();
        $this->presence = (new CacheBackedFormPresenceStore())->withCacheManager(new ArrayCacheManager());
        $this->locks = (new CacheBackedFormLockStore())->withCacheManager(new ArrayCacheManager());
        $this->projector = (new FormDocumentProjector())->withStores($this->draft, $this->presence, $this->locks);
    }

    #[Test]
    public function an_unopened_document_projects_an_empty_record_at_version_zero(): void
    {
        $snapshot = $this->projector->project(self::SCOPE, FormCollaborationMode::Shared);

        self::assertSame(self::SCOPE, $snapshot->scopeKey);
        self::assertSame([], $snapshot->values);
        self::assertSame(0, $snapshot->version);
        self::assertNull($snapshot->origin);
        self::assertSame([], $snapshot->presence);
        self::assertSame(FormCollaborationMode::Shared, $snapshot->mode);
    }

    #[Test]
    public function it_projects_the_merged_draft_with_version_and_origin(): void
    {
        // Two shared field edits by distinct authors — last-write-wins merge.
        $this->draft->mergeFields(self::SCOPE, ['title' => 'Hello'], 'actor-A');
        $this->draft->mergeFields(self::SCOPE, ['body' => 'World'], 'actor-B');

        $snapshot = $this->projector->project(self::SCOPE, FormCollaborationMode::Shared);

        self::assertSame(['title' => 'Hello', 'body' => 'World'], $snapshot->values);
        self::assertSame(2, $snapshot->version);
        // origin = the LAST writer — the echo-suppression coordinate the client
        // compares against itself to avoid clobbering its own in-flight edit.
        self::assertSame('actor-B', $snapshot->origin);
    }

    #[Test]
    public function it_renders_the_presence_roster_from_the_presence_store(): void
    {
        $this->presence->ping(self::SCOPE, 'actor-A', 'Ada', 'editor');
        $this->presence->ping(self::SCOPE, 'actor-B', 'Linus', 'editor');

        $snapshot = $this->projector->project(self::SCOPE, FormCollaborationMode::Shared);

        self::assertCount(2, $snapshot->presence);
        $ids = array_map(static fn ($p) => $p->participantId, $snapshot->presence);
        self::assertContains('actor-A', $ids);
        self::assertContains('actor-B', $ids);
    }

    #[Test]
    public function the_envelope_carries_the_shared_record_and_the_collaboration_meta(): void
    {
        $this->draft->mergeFields(self::SCOPE, ['title' => 'Hi'], 'actor-A');
        $this->presence->ping(self::SCOPE, 'actor-A', 'Ada', 'editor');

        $envelope = $this->projector->project(self::SCOPE, FormCollaborationMode::Shared)->toEnvelope();

        self::assertSame('Hi', $envelope['data']['values']['title']);
        self::assertSame(1, $envelope['data']['version']);
        self::assertSame('actor-A', $envelope['data']['origin']);
        self::assertSame('shared', $envelope['meta']['mode']);
        self::assertCount(1, $envelope['meta']['presence']);
        self::assertSame('Ada', $envelope['meta']['presence'][0]['label']);
    }

    #[Test]
    public function the_projection_carries_the_resolved_mode_through(): void
    {
        // The projector is mode-agnostic on reads — it echoes whichever mode the
        // signed config resolved, so the client renders the right per-mode UX.
        $snapshot = $this->projector->project(self::SCOPE, FormCollaborationMode::FieldLock);

        self::assertSame(FormCollaborationMode::FieldLock, $snapshot->mode);
        self::assertSame('field-lock', $snapshot->toEnvelope()['meta']['mode']);
    }

    #[Test]
    public function form_lock_mode_projects_the_whole_form_lock_holder(): void
    {
        $this->locks->acquire(self::SCOPE, null, 'h1', 'Alice');

        $envelope = $this->projector->project(self::SCOPE, FormCollaborationMode::FormLock)->toEnvelope();

        self::assertCount(1, $envelope['meta']['locks']);
        $lock = $envelope['meta']['locks'][0];
        self::assertNull($lock['field'], 'whole-form lock carries a null field');
        self::assertSame('h1', $lock['holderId']);
        self::assertSame('Alice', $lock['holderLabel']);
    }

    #[Test]
    public function field_lock_mode_projects_only_the_declared_fields_locks(): void
    {
        $this->locks->acquire(self::SCOPE, 'title', 'h1', 'Alice');
        $this->locks->acquire(self::SCOPE, 'body', 'h2', 'Bob');

        $envelope = $this->projector
            ->project(self::SCOPE, FormCollaborationMode::FieldLock, ['title', 'body'])
            ->toEnvelope();

        $byField = [];
        foreach ($envelope['meta']['locks'] as $lock) {
            $byField[$lock['field']] = $lock['holderLabel'];
        }
        self::assertSame(['title' => 'Alice', 'body' => 'Bob'], $byField);
    }

    #[Test]
    public function the_lock_free_modes_project_no_locks(): void
    {
        $this->locks->acquire(self::SCOPE, null, 'h1', 'Alice');

        // Even with a stray lock present, Shared/Optimistic never surface it —
        // the lock-free modes are not lock-gated.
        foreach ([FormCollaborationMode::Shared, FormCollaborationMode::Optimistic] as $mode) {
            $envelope = $this->projector->project(self::SCOPE, $mode)->toEnvelope();
            self::assertSame([], $envelope['meta']['locks'], $mode->value . ' projects no locks');
        }
    }
}
