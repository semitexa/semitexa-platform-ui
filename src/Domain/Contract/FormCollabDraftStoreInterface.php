<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Contract;

use Semitexa\PlatformUi\Domain\Exception\FormDraftVersionConflictException;
use Semitexa\PlatformUi\Domain\Model\Collaboration\FormCollabDraftState;

/**
 * Collaborative Form Data · Phase 2 — the durable draft-store contract: read,
 * idempotently open, optimistically replace, and last-write-wins merge the
 * shared draft of one collaborative form document (keyed by its
 * `formdoc:{formKey}:{recordId}` scope key).
 *
 * The modes consume these primitives:
 *   - Optimistic uses {@see apply()} (full replace under a version guard);
 *   - Shared / Field-lock use {@see mergeFields()} (per-field broadcast,
 *     last-write-wins, no guard);
 *   - every mode uses {@see open()} on connect to seed/fetch the draft.
 *
 * A draft ENDS in one of two ways, and without them an unsaved edit outlives
 * the record it was made on (the next editor's inputs were overwritten with a
 * days-old value, which their save then wrote into the record):
 *   - {@see discard()}, once the app has persisted the record (apps call
 *     {@see \Semitexa\PlatformUi\Application\Service\Collaboration\FormCollaborationDrafts},
 *     which discards AND tells the open editors);
 *   - expiry: a draft nobody wrote to or was present in for the retention
 *     window ({@see \Semitexa\PlatformUi\Application\Service\Collaboration\FormCollabDraftRetention})
 *     is dropped by the next read or write, so every method below behaves as
 *     if it had never been opened.
 *
 * The store owns persistence ONLY — it does NOT publish invalidations; the
 * inbound handler touches the document scope after a write so the feed
 * re-projects to subscribers.
 */
interface FormCollabDraftStoreInterface
{
    /** The current draft for a scope, or null if none has been opened. */
    public function load(string $scopeKey): ?FormCollabDraftState;

    /**
     * Idempotent open: return the existing draft, or seed a fresh one at
     * version 1 from `$seedValues` (the persisted record's current values, on a
     * first edit). Never raises on an already-open document.
     *
     * @param array<string, scalar|null> $seedValues
     */
    public function open(string $scopeKey, array $seedValues, ?string $actor): FormCollabDraftState;

    /**
     * Full replace of the draft values under the optimistic version guard. The
     * caller passes the version it last read; if the draft has since advanced,
     * the write is rejected.
     *
     * The version restarts at 1 with every new draft, so the number alone can
     * match a draft the caller never read. `$expectedEpoch` names the draft the
     * caller read ({@see FormCollabDraftState::$epoch}, '' for "none"): the
     * store compares it on the very row it then writes, so a draft discarded
     * and replaced between the caller's read and this write is a conflict and
     * not an overwrite. Null skips that check (a caller that holds no epoch).
     *
     * @param array<string, scalar|null> $values
     * @throws FormDraftVersionConflictException when `$expectedVersion` is stale or the draft is not `$expectedEpoch`
     */
    public function apply(string $scopeKey, array $values, int $expectedVersion, ?string $actor, ?string $expectedEpoch = null): FormCollabDraftState;

    /**
     * Last-write-wins merge of partial field values into the draft (the live
     * co-editing path). No version guard — each field edit wins for its own
     * field and bumps the version. Opens the draft if absent.
     *
     * @param array<string, scalar|null> $partialValues
     */
    public function mergeFields(string $scopeKey, array $partialValues, ?string $actor): FormCollabDraftState;

    /**
     * End the draft: the record was persisted (or the edit abandoned), so what
     * the draft held is no longer an edit in progress. Afterwards {@see load()}
     * answers null and {@see open()} seeds afresh at version 1. Idempotent; a
     * concurrent edit that lands after it starts a new draft. Persistence only:
     * the editors holding the form open are told by the caller's scope touch.
     */
    public function discard(string $scopeKey): void;
}
