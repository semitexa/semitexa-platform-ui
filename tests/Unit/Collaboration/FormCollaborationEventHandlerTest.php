<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Collaboration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Collaboration\CacheBackedFormLockStore;
use Semitexa\PlatformUi\Application\Service\Collaboration\CacheBackedFormPresenceStore;
use Semitexa\PlatformUi\Application\Service\Collaboration\FormCollaborationEventHandler;
use Semitexa\PlatformUi\Application\Service\Collaboration\InMemoryFormCollabDraftStore;
use Semitexa\PlatformUi\Domain\Contract\FormCollabDraftStoreInterface;
use Semitexa\PlatformUi\Domain\Contract\FormLockStoreInterface;
use Semitexa\PlatformUi\Domain\Contract\FormPresenceStoreInterface;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventResponseStatus;
use Semitexa\PlatformUi\Tests\Support\ArrayCacheManager;
use Semitexa\Ssr\Domain\Contract\ScopeInvalidatorInterface;

/**
 * Collaborative Form Data · Phase 3 — the inbound command handler: it gates
 * edits by mode policy, mutates the draft/presence/lock stores, touches the
 * document scope (so the feed re-projects), and returns thin acks / typed
 * errors. Driven with in-memory/cache-backed stores + a recording invalidator.
 */
final class FormCollaborationEventHandlerTest extends TestCase
{
    private const SCOPE = 'formdoc:article:42';

    private FormCollabDraftStoreInterface $draft;
    private FormPresenceStoreInterface $presence;
    private FormLockStoreInterface $lock;
    private RecordingScopeInvalidator $invalidator;
    private FormCollaborationEventHandler $handler;

    protected function setUp(): void
    {
        $cache = new ArrayCacheManager();
        $this->draft = new InMemoryFormCollabDraftStore();
        $this->presence = (new CacheBackedFormPresenceStore())->withCacheManager($cache);
        $this->lock = (new CacheBackedFormLockStore())->withCacheManager($cache);
        $this->invalidator = new RecordingScopeInvalidator();
        $this->handler = (new FormCollaborationEventHandler())->withCollaborationDeps(
            $this->draft,
            $this->presence,
            $this->lock,
            $this->invalidator,
        );
    }

    /** @param array<string, mixed> $request */
    private function ctx(string $event, string $mode, array $request = []): UiEventContext
    {
        return new UiEventContext(
            eventId: 'evt',
            correlationId: 'cor',
            semanticEvent: $event,
            signedClaims: ['i' => 'actor-A', 'cfg' => ['scope' => self::SCOPE, 'mode' => $mode, 'fields' => ['title', 'body']]],
            request: $request,
        );
    }

    #[Test]
    public function a_missing_or_invalid_scope_is_rejected(): void
    {
        $bad = new UiEventContext('e', 'c', 'field.edit', ['i' => 'a', 'cfg' => ['mode' => 'shared']], []);
        $resp = $this->handler->handle((object) [], $bad);

        self::assertSame(UiEventResponseStatus::Error, $resp->status);
        self::assertSame('invalid_scope', $resp->error?->code);
    }

    #[Test]
    public function an_unknown_event_is_rejected(): void
    {
        $resp = $this->handler->handle((object) [], $this->ctx('field.teleport', 'shared'));

        self::assertSame('unknown_collab_event', $resp->error?->code);
    }

    #[Test]
    public function shared_field_edit_merges_the_draft_and_touches_the_scope(): void
    {
        $resp = $this->handler->handle((object) [], $this->ctx('field.edit', 'shared', ['field' => 'title', 'value' => 'Hi']));

        self::assertSame(UiEventResponseStatus::Ok, $resp->status);
        self::assertSame(['title' => 'Hi'], $this->draft->load(self::SCOPE)?->values);
        self::assertContains(self::SCOPE, $this->invalidator->touched);
    }

    #[Test]
    public function a_field_edit_for_a_field_off_the_signed_allow_list_is_rejected(): void
    {
        // 'secret' is not in the signed cfg fields (['title','body']); the body is
        // untrusted, so it must never reach the shared draft.
        $resp = $this->handler->handle((object) [], $this->ctx('field.edit', 'shared', ['field' => 'secret', 'value' => 'x']));

        self::assertSame('unknown_field', $resp->error?->code);
        self::assertNull($this->draft->load(self::SCOPE), 'an off-list field must not create a draft');
        self::assertNotContains(self::SCOPE, $this->invalidator->touched);
    }

    #[Test]
    public function form_save_drops_keys_off_the_signed_allow_list(): void
    {
        $resp = $this->handler->handle((object) [], $this->ctx('form.save', 'optimistic', [
            'values' => ['title' => 'One', 'secret' => 'leak'],
            'version' => 0,
        ]));

        self::assertSame(UiEventResponseStatus::Ok, $resp->status);
        // 'secret' is filtered out; only the allow-listed key persists.
        self::assertSame(['title' => 'One'], $this->draft->load(self::SCOPE)?->values);
    }

    #[Test]
    public function field_edit_without_a_field_name_is_rejected(): void
    {
        $resp = $this->handler->handle((object) [], $this->ctx('field.edit', 'shared', ['value' => 'x']));

        self::assertSame('missing_field', $resp->error?->code);
    }

    #[Test]
    public function form_lock_mode_denies_a_field_edit_without_the_lock(): void
    {
        $resp = $this->handler->handle((object) [], $this->ctx('field.edit', 'form-lock', ['field' => 'title', 'value' => 'Hi']));

        self::assertSame('field_locked', $resp->error?->code);
        self::assertNull($this->draft->load(self::SCOPE)); // nothing written
    }

    #[Test]
    public function form_lock_mode_allows_a_field_edit_once_the_actor_holds_the_lock(): void
    {
        // actor-A acquires the whole-form lock, then edits.
        $this->handler->handle((object) [], $this->ctx('lock.acquire', 'form-lock'));
        $resp = $this->handler->handle((object) [], $this->ctx('field.edit', 'form-lock', ['field' => 'title', 'value' => 'Hi']));

        self::assertSame(UiEventResponseStatus::Ok, $resp->status);
        self::assertSame(['title' => 'Hi'], $this->draft->load(self::SCOPE)?->values);
    }

    #[Test]
    public function lock_acquire_grants_then_denies_a_second_holder(): void
    {
        $a = $this->handler->handle((object) [], $this->ctx('lock.acquire', 'form-lock'));
        self::assertSame(UiEventResponseStatus::Ok, $a->status);

        // A different actor (distinct instance id) is denied.
        $otherCtx = new UiEventContext('e', 'c', 'lock.acquire', ['i' => 'actor-B', 'cfg' => ['scope' => self::SCOPE, 'mode' => 'form-lock']], []);
        $b = $this->handler->handle((object) [], $otherCtx);

        self::assertSame('lock_unavailable', $b->error?->code);
        self::assertSame('actor-A', $b->error?->details['holderId']);
    }

    #[Test]
    public function form_save_applies_under_the_version_guard_and_conflicts_on_stale_version(): void
    {
        $first = $this->handler->handle((object) [], $this->ctx('form.save', 'optimistic', ['values' => ['title' => 'One'], 'version' => 0]));
        self::assertSame(UiEventResponseStatus::Ok, $first->status);
        self::assertSame(1, $this->draft->load(self::SCOPE)?->version);
        // The draft origin must be the STABLE actor id (the echo-suppression
        // coordinate), not the display label. ctx() uses an anonymous actor
        // whose id is 'actor-A' and whose label resolves to 'Guest'; stamping
        // the label here would break the author's optimistic-echo suppression.
        self::assertSame('actor-A', $this->draft->load(self::SCOPE)?->updatedBy);

        // Re-saving with the now-stale version 0 conflicts.
        $stale = $this->handler->handle((object) [], $this->ctx('form.save', 'optimistic', ['values' => ['title' => 'Two'], 'version' => 0]));
        self::assertSame('form_draft_version_conflict', $stale->error?->code);
        self::assertSame(1, $stale->error?->details['currentVersion']);
    }

    #[Test]
    public function lock_heartbeat_succeeds_for_the_holder_and_fails_after_loss(): void
    {
        $this->handler->handle((object) [], $this->ctx('lock.acquire', 'form-lock'));
        $ok = $this->handler->handle((object) [], $this->ctx('lock.heartbeat', 'form-lock'));
        self::assertSame(UiEventResponseStatus::Ok, $ok->status);

        // A non-holder heartbeating gets lock_lost.
        $otherCtx = new UiEventContext('e', 'c', 'lock.heartbeat', ['i' => 'actor-B', 'cfg' => ['scope' => self::SCOPE, 'mode' => 'form-lock']], []);
        $lost = $this->handler->handle((object) [], $otherCtx);
        self::assertSame('lock_lost', $lost->error?->code);
    }

    #[Test]
    public function presence_ping_registers_the_actor_and_touches_the_scope(): void
    {
        $resp = $this->handler->handle((object) [], $this->ctx('presence.ping', 'shared', ['role' => 'editor']));

        self::assertSame(UiEventResponseStatus::Ok, $resp->status);
        $roster = $this->presence->roster(self::SCOPE);
        self::assertCount(1, $roster);
        self::assertSame('actor-A', $roster[0]->participantId);
        self::assertContains(self::SCOPE, $this->invalidator->touched);
    }
}

/** Recording stand-in for the scope invalidator. */
final class RecordingScopeInvalidator implements ScopeInvalidatorInterface
{
    /** @var list<string> */
    public array $touched = [];

    public function touch(string $scope): void
    {
        $this->touched[] = $scope;
    }
}
