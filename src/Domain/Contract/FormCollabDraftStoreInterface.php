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
     * @param array<string, scalar|null> $values
     * @throws FormDraftVersionConflictException when `$expectedVersion` is stale
     */
    public function apply(string $scopeKey, array $values, int $expectedVersion, ?string $actor): FormCollabDraftState;

    /**
     * Last-write-wins merge of partial field values into the draft (the live
     * co-editing path). No version guard — each field edit wins for its own
     * field and bumps the version. Opens the draft if absent.
     *
     * @param array<string, scalar|null> $partialValues
     */
    public function mergeFields(string $scopeKey, array $partialValues, ?string $actor): FormCollabDraftState;
}
