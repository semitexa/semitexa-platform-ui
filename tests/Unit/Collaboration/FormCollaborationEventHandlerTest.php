<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Collaboration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Collaboration\CacheBackedFormLockStore;
use Semitexa\PlatformUi\Application\Service\Collaboration\CacheBackedFormPresenceStore;
use Semitexa\PlatformUi\Application\Service\Collaboration\FormCollaborationEventHandler;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\PlatformUi\Application\Service\Collaboration\InMemoryFormCollabDraftStore;
use Semitexa\PlatformUi\Domain\Contract\FormCollabDraftStoreInterface;
use Semitexa\PlatformUi\Domain\Contract\FormLockStoreInterface;
use Semitexa\PlatformUi\Domain\Contract\FormPresenceStoreInterface;
use Semitexa\PlatformUi\Domain\Exception\FormDraftVersionConflictException;
use Semitexa\PlatformUi\Domain\Model\Collaboration\FormCollabDraftState;
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

    /**
     * A sequenced field.edit of `$field`, as page `$runtime` of `$actor` sends it.
     */
    private function edit(string $value, ?int $seq, string $runtime = 'runtime-one', string $actor = 'actor-A', string $field = 'title'): UiEventContext
    {
        return new UiEventContext(
            eventId: 'evt',
            correlationId: 'cor',
            semanticEvent: 'field.edit',
            signedClaims: ['i' => $actor, 'cfg' => ['scope' => self::SCOPE, 'mode' => 'shared', 'fields' => ['title', 'body']]],
            request: ['field' => $field, 'value' => $value] + ($seq === null ? [] : ['seq' => $seq, 'runtime' => $runtime]),
        );
    }

    #[Test]
    public function an_edit_older_than_one_stored_from_the_same_page_is_refused(): void
    {
        self::assertSame(UiEventResponseStatus::Ok, $this->handler->handle((object) [], $this->edit('ab', 2))->status);
        $touches = count($this->invalidator->touched);

        $late = $this->handler->handle((object) [], $this->edit('a', 1));

        self::assertSame(FormCollaborationEventHandler::EDIT_SUPERSEDED, $late->error?->code);
        self::assertSame(['field' => 'title'], $late->error->details);
        self::assertSame(['title' => 'ab'], $this->draft->load(self::SCOPE)?->values, 'the newer value stands');
        self::assertCount($touches, $this->invalidator->touched, 'nothing changed, nobody is told');
    }

    #[Test]
    public function an_edit_arriving_a_second_time_is_acknowledged_and_written_once(): void
    {
        // The page got no answer and sent the same edit again; meanwhile
        // someone else typed into the field.
        $this->handler->handle((object) [], $this->edit('mine', 1));
        $this->handler->handle((object) [], $this->edit('theirs', 1, 'runtime-two', 'actor-B'));
        $touches = count($this->invalidator->touched);

        $again = $this->handler->handle((object) [], $this->edit('mine', 1));

        self::assertSame(UiEventResponseStatus::Ok, $again->status, 'the server has it: an answer the page stops on');
        self::assertSame(['title' => 'theirs'], $this->draft->load(self::SCOPE)?->values, 'not written over what was typed since');
        self::assertCount($touches, $this->invalidator->touched);
    }

    #[Test]
    public function two_tabs_of_one_person_do_not_refuse_each_others_edits(): void
    {
        // One actor, so one lock holder; each tab counts its own edits.
        $this->handler->handle((object) [], $this->edit('from the first tab', 7, 'runtime-one'));

        $other = $this->handler->handle((object) [], $this->edit('from the second tab', 1, 'runtime-two'));

        self::assertSame(UiEventResponseStatus::Ok, $other->status);
        self::assertSame(['title' => 'from the second tab'], $this->draft->load(self::SCOPE)?->values);

        // And each is still ordered against itself.
        self::assertSame(
            FormCollaborationEventHandler::EDIT_SUPERSEDED,
            $this->handler->handle((object) [], $this->edit('late, first tab', 6, 'runtime-one'))->error?->code,
        );
    }

    #[Test]
    public function edits_of_different_people_stay_in_arrival_order_even_under_one_runtime_id(): void
    {
        // The runtime id is the body's word. Naming someone else's does not
        // put an edit in their sequence: the actor is part of the key.
        $this->handler->handle((object) [], $this->edit('alice', 9, 'runtime-one', 'actor-A'));

        $bob = $this->handler->handle((object) [], $this->edit('bob', 1, 'runtime-one', 'actor-B'));

        self::assertSame(UiEventResponseStatus::Ok, $bob->status);
        self::assertSame(['title' => 'bob'], $this->draft->load(self::SCOPE)?->values, 'whoever arrives last, as before');
        self::assertSame(UiEventResponseStatus::Ok, $this->handler->handle((object) [], $this->edit('alice again', 10, 'runtime-one', 'actor-A'))->status);
    }

    #[Test]
    public function a_lower_sequence_for_another_field_is_not_an_older_edit(): void
    {
        // A page sends its fields side by side; they overtake each other.
        $this->handler->handle((object) [], $this->edit('title typed second', 5, field: 'title'));

        $body = $this->handler->handle((object) [], $this->edit('body typed first', 4, field: 'body'));

        self::assertSame(UiEventResponseStatus::Ok, $body->status);
        self::assertSame(['title' => 'title typed second', 'body' => 'body typed first'], $this->draft->load(self::SCOPE)?->values);
    }

    #[Test]
    public function an_edit_that_names_no_usable_sequence_is_merged_as_it_arrives(): void
    {
        $this->handler->handle((object) [], $this->edit('sequenced', 5));

        // A page from before the sequence, and bodies that are not one.
        $bodies = [
            'none' => [],
            'sequence as text' => ['seq' => '1', 'runtime' => 'runtime-one'],
            'sequence of zero' => ['seq' => 0, 'runtime' => 'runtime-one'],
            'no runtime' => ['seq' => 1],
            'runtime too short' => ['seq' => 1, 'runtime' => 'r1'],
            'runtime with other characters' => ['seq' => 1, 'runtime' => "runtime one\n"],
            'runtime too long' => ['seq' => 1, 'runtime' => str_repeat('r', 65)],
        ];
        foreach ($bodies as $case => $body) {
            $resp = $this->handler->handle((object) [], $this->ctx('field.edit', 'shared', ['field' => 'title', 'value' => $case] + $body));

            self::assertSame(UiEventResponseStatus::Ok, $resp->status, $case);
            self::assertSame(['title' => $case], $this->draft->load(self::SCOPE)?->values, $case);
        }
    }

    #[Test]
    public function the_sequence_ends_with_the_draft(): void
    {
        $this->handler->handle((object) [], $this->edit('before the save', 5));
        $this->draft->discard(self::SCOPE);

        $resp = $this->handler->handle((object) [], $this->edit('a page that counts from one', 1));

        self::assertSame(UiEventResponseStatus::Ok, $resp->status, 'a new draft has stored nothing yet');
    }

    #[Test]
    public function a_store_that_keeps_no_marks_merges_sequenced_edits_as_they_arrive(): void
    {
        $plain = new class ($this->draft) implements FormCollabDraftStoreInterface {
            public function __construct(private readonly FormCollabDraftStoreInterface $inner)
            {
            }

            public function load(string $scopeKey): ?FormCollabDraftState
            {
                return $this->inner->load($scopeKey);
            }

            public function open(string $scopeKey, array $seedValues, ?string $actor): FormCollabDraftState
            {
                return $this->inner->open($scopeKey, $seedValues, $actor);
            }

            public function apply(string $scopeKey, array $values, int $expectedVersion, ?string $actor, ?string $expectedEpoch = null): FormCollabDraftState
            {
                return $this->inner->apply($scopeKey, $values, $expectedVersion, $actor, $expectedEpoch);
            }

            public function mergeFields(string $scopeKey, array $partialValues, ?string $actor): FormCollabDraftState
            {
                return $this->inner->mergeFields($scopeKey, $partialValues, $actor);
            }

            public function discard(string $scopeKey): void
            {
                $this->inner->discard($scopeKey);
            }
        };
        $handler = (new FormCollaborationEventHandler())->withCollaborationDeps($plain, $this->presence, $this->lock, $this->invalidator);

        $handler->handle((object) [], $this->edit('ab', 2));
        $late = $handler->handle((object) [], $this->edit('a', 1));

        self::assertSame(UiEventResponseStatus::Ok, $late->status, "an app's own store keeps working, unordered as it was");
        self::assertSame(['title' => 'a'], $this->draft->load(self::SCOPE)?->values);
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
            'epoch' => '',
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
        $first = $this->handler->handle((object) [], $this->ctx('form.save', 'optimistic', ['values' => ['title' => 'One'], 'version' => 0, 'epoch' => '']));
        self::assertSame(UiEventResponseStatus::Ok, $first->status);
        self::assertSame(1, $this->draft->load(self::SCOPE)?->version);
        // The draft origin must be the STABLE actor id (the echo-suppression
        // coordinate), not the display label. ctx() uses an anonymous actor
        // whose id is 'actor-A' and whose label resolves to 'Guest'; stamping
        // the label here would break the author's optimistic-echo suppression.
        self::assertSame('actor-A', $this->draft->load(self::SCOPE)?->updatedBy);

        // Re-saving with the now-stale version 0 conflicts. The epoch is the
        // current draft's, so it is the VERSION guard that refuses.
        $epoch = $this->draft->load(self::SCOPE)->epoch;
        self::assertNotSame('', $epoch);
        $stale = $this->handler->handle((object) [], $this->ctx('form.save', 'optimistic', ['values' => ['title' => 'Two'], 'version' => 0, 'epoch' => $epoch]));
        self::assertSame('form_draft_version_conflict', $stale->error?->code);
        self::assertSame(1, $stale->error?->details['currentVersion']);
    }

    #[Test]
    public function a_save_that_names_no_draft_is_told_the_page_is_out_of_date(): void
    {
        // What a runtime from before the epoch sends (a tab left open across a
        // deploy): the right version number, and nothing saying which draft.
        // Not a silent pass, and not the "document changed" conflict either:
        // that prompt offers a retry this runtime can never win.
        $this->draft->apply(self::SCOPE, ['title' => 'Theirs'], 0, 'someone');

        $resp = $this->handler->handle((object) [], $this->ctx('form.save', 'optimistic', ['values' => ['title' => 'Mine'], 'version' => 1]));

        self::assertSame(FormCollaborationEventHandler::OUTDATED_CLIENT, $resp->error?->code);
        self::assertSame('This page is out of date. Reload to continue.', $resp->error->message);
        self::assertSame(['title' => 'Theirs'], $this->draft->load(self::SCOPE)?->values);
    }

    #[Test]
    public function the_ack_of_a_save_says_what_the_next_save_is_based_on(): void
    {
        $resp = $this->handler->handle((object) [], $this->ctx('form.save', 'optimistic', ['values' => ['title' => 'One'], 'version' => 0, 'epoch' => '']));

        $stored = $this->draft->load(self::SCOPE);
        self::assertNotNull($stored);
        self::assertNotSame('', $stored->epoch);
        self::assertSame(['collab' => ['epoch' => $stored->epoch, 'version' => 1]], $resp->frontend);
    }

    #[Test]
    public function a_conflict_says_what_a_retry_must_be_based_on(): void
    {
        $current = $this->draft->apply(self::SCOPE, ['title' => 'Theirs'], 0, 'someone');

        $resp = $this->handler->handle((object) [], $this->ctx('form.save', 'optimistic', ['values' => ['title' => 'Mine'], 'version' => 0, 'epoch' => '']));

        self::assertSame('form_draft_version_conflict', $resp->error?->code);
        self::assertSame(['epoch' => $current->epoch, 'version' => 1], ['epoch' => $resp->error->details['currentEpoch'], 'version' => $resp->error->details['currentVersion']]);
    }

    #[Test]
    public function a_save_based_on_a_draft_that_was_replaced_conflicts_at_the_same_version_number(): void
    {
        $old = $this->draft->apply(self::SCOPE, ['title' => 'Old draft'], 0, 'someone');
        $this->draft->discard(self::SCOPE);
        $new = $this->draft->apply(self::SCOPE, ['title' => 'New draft'], 0, 'someone');
        self::assertSame($old->version, $new->version);

        $stale = $this->handler->handle((object) [], $this->ctx('form.save', 'optimistic', ['values' => ['title' => 'Mine'], 'version' => 1, 'epoch' => $old->epoch]));
        self::assertSame('form_draft_version_conflict', $stale->error?->code);
        self::assertSame(['title' => 'New draft'], $this->draft->load(self::SCOPE)?->values);

        $current = $this->handler->handle((object) [], $this->ctx('form.save', 'optimistic', ['values' => ['title' => 'Mine'], 'version' => 1, 'epoch' => $new->epoch]));
        self::assertSame(UiEventResponseStatus::Ok, $current->status);
        self::assertSame(['title' => 'Mine'], $this->draft->load(self::SCOPE)?->values);
    }

    #[Test]
    public function a_draft_replaced_between_the_check_and_the_write_is_not_overwritten(): void
    {
        // The save is validated, and before the store writes, the app discards
        // the draft and another editor's save starts the next one, which
        // reaches the same version number. A check made apart from the write
        // (the handler's own load, then apply by version) let the old save
        // through onto the new draft.
        $store = new class extends InMemoryFormCollabDraftStore {
            public ?\Closure $beforeWrite = null;

            public function apply(string $scopeKey, array $values, int $expectedVersion, ?string $actor, ?string $expectedEpoch = null): FormCollabDraftState
            {
                $interleaved = $this->beforeWrite;
                $this->beforeWrite = null;
                if ($interleaved !== null) {
                    $interleaved();
                }

                return parent::apply($scopeKey, $values, $expectedVersion, $actor, $expectedEpoch);
            }
        };
        $old = $store->apply(self::SCOPE, ['title' => 'Old draft'], 0, 'alice');
        $store->beforeWrite = static function () use ($store): void {
            $store->discard(self::SCOPE);
            $store->apply(self::SCOPE, ['title' => "Carol's new draft"], 0, 'carol');
        };
        $handler = (new FormCollaborationEventHandler())->withCollaborationDeps($store, $this->presence, $this->lock, $this->invalidator);

        $resp = $handler->handle((object) [], $this->ctx('form.save', 'optimistic', ['values' => ['title' => 'Mine, on the old draft'], 'version' => 1, 'epoch' => $old->epoch]));

        self::assertSame('form_draft_version_conflict', $resp->error?->code);
        self::assertSame(["title" => "Carol's new draft"], $store->load(self::SCOPE)?->values);
        self::assertSame(1, $store->load(self::SCOPE)->version, 'the same number the old save carried');
    }

    /** Review, platform-ui#92: the epoch of the later read beside the version of the earlier one sent Keep mine into a second conflict. */
    #[Test]
    public function a_conflict_names_the_epoch_and_the_version_of_one_read(): void
    {
        $store = new class extends InMemoryFormCollabDraftStore {
            public function apply(string $scopeKey, array $values, int $expectedVersion, ?string $actor, ?string $expectedEpoch = null): FormCollabDraftState
            {
                // The write met version 1 of another draft, which ends before the handler reads the base.
                $this->discard($scopeKey);

                throw new FormDraftVersionConflictException($scopeKey, $expectedVersion, 1);
            }
        };
        $handler = (new FormCollaborationEventHandler())->withCollaborationDeps($store, $this->presence, $this->lock, $this->invalidator);

        $resp = $handler->handle((object) [], $this->ctx('form.save', 'optimistic', ['values' => ['title' => 'Mine'], 'version' => 1, 'epoch' => 'e-old']));

        self::assertSame('form_draft_version_conflict', $resp->error?->code);
        self::assertSame(['currentEpoch' => '', 'currentVersion' => 0], [
            'currentEpoch'   => $resp->error->details['currentEpoch'] ?? 'missing',
            'currentVersion' => $resp->error->details['currentVersion'] ?? 'missing',
        ]);
    }

    #[Test]
    public function a_save_based_on_a_draft_that_ended_conflicts_with_current_version_zero(): void
    {
        $old = $this->draft->apply(self::SCOPE, ['title' => 'Old draft'], 0, 'someone');
        $this->draft->discard(self::SCOPE);

        $resp = $this->handler->handle((object) [], $this->ctx('form.save', 'optimistic', ['values' => ['title' => 'Mine'], 'version' => 1, 'epoch' => $old->epoch]));

        self::assertSame('form_draft_version_conflict', $resp->error?->code);
        self::assertSame(0, $resp->error->details['currentVersion']);
        self::assertNull($this->draft->load(self::SCOPE));
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

    #[Test]
    public function a_real_persist_failure_is_logged_structured_not_swallowed_silently(): void
    {
        // A genuine store failure on the form.save WRITE path (not the typed
        // optimistic conflict) used to collapse into a generic client error with
        // no server-side breadcrumb. The client still gets the generic error, but
        // the failure must now be logged structured so it is diagnosable.
        $logger = new CapturingLogger();
        StaticLoggerBridge::set($logger);

        try {
            $handler = (new FormCollaborationEventHandler())->withCollaborationDeps(
                new ThrowingDraftStore(),
                $this->presence,
                $this->lock,
                $this->invalidator,
            );

            $resp = $handler->handle((object) [], $this->ctx('form.save', 'optimistic', [
                'values' => ['title' => 'One'],
                'version' => 0,
                'epoch' => '',
            ]));

            // The client is told generically — internals never leak.
            self::assertSame(UiEventResponseStatus::Error, $resp->status);
            self::assertSame('collab_error', $resp->error?->code);

            // ...but the server logged the real failure with structured context.
            self::assertCount(1, $logger->errors, 'the persist failure must be logged, not swallowed');
            [$message, $context] = $logger->errors[0];
            self::assertStringContainsString('Collaborative form event failed', $message);
            self::assertSame('form.save', $context['event']);
            self::assertSame(self::SCOPE, $context['scope']);
            self::assertSame(\RuntimeException::class, $context['exception']);
            self::assertSame('draft store offline', $context['message']);
        } finally {
            StaticLoggerBridge::reset();
        }
    }
}

/** A draft store whose write path fails, to drive the \Throwable catch. */
final class ThrowingDraftStore implements FormCollabDraftStoreInterface
{
    public function load(string $scopeKey): ?FormCollabDraftState
    {
        return null;
    }

    public function open(string $scopeKey, array $seedValues, ?string $actor): FormCollabDraftState
    {
        throw new \RuntimeException('draft store offline');
    }

    public function apply(string $scopeKey, array $values, int $expectedVersion, ?string $actor, ?string $expectedEpoch = null): FormCollabDraftState
    {
        throw new \RuntimeException('draft store offline');
    }

    public function mergeFields(string $scopeKey, array $partialValues, ?string $actor): FormCollabDraftState
    {
        throw new \RuntimeException('draft store offline');
    }

    public function discard(string $scopeKey): void
    {
        throw new \RuntimeException('draft store offline');
    }
}

final class CapturingLogger implements LoggerInterface
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $errors = [];

    public function error(string $message, array $context = []): void
    {
        $this->errors[] = [$message, $context];
    }

    public function critical(string $message, array $context = []): void
    {
    }

    public function warning(string $message, array $context = []): void
    {
    }

    public function info(string $message, array $context = []): void
    {
    }

    public function notice(string $message, array $context = []): void
    {
    }

    public function debug(string $message, array $context = []): void
    {
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
