<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Collaboration;

/**
 * Collaborative Form Data · Phase 3 (Shared mode) — the immutable read
 * projection of one collaborative document's live shared state, as the document
 * feed pushes it to every editor.
 *
 * The READ sibling of {@see FormCollabDraftState}: where the draft state is the
 * store's persistence view (values + version + audit), the snapshot is the
 * WIRE view a subscriber renders — the merged draft (Shared mode's
 * last-write-wins field map) plus the live presence roster and the resolved
 * mode. It carries no behaviour beyond {@see toEnvelope()}, the canonical
 * `{data, meta}` shape the {@see \Semitexa\Ssr\Application\Handler\PayloadHandler\AbstractSseDocumentFeedHandler}
 * frames as `ui.document.data`.
 *
 * `origin` is the last writer's STABLE participant id — the echo-suppression
 * coordinate, NOT the display label (anonymous editors share the label "Guest"
 * but get distinct ids, so the label cannot discriminate self from peer). Every
 * field edit touches the document scope, which re-projects the WHOLE snapshot
 * to all subscribers including the author; the client compares `origin` against
 * its own actor id (`self`) and SKIPS re-applying a snapshot it itself caused,
 * so a remote delta never clobbers the field the author is still typing into.
 * The server surfaces the coordinate; the suppression decision is the client
 * runtime's (a later phase).
 */
final readonly class FormDocumentSnapshot
{
    /**
     * @param array<string, scalar|null> $values field name → current shared value
     * @param int $version optimistic-concurrency coordinate (0 when no draft yet)
     * @param ?string $origin stable participant id of the last writer (echo-suppression key, NOT the label); null when no draft yet
     * @param int $updatedAt unix timestamp of the last write (0 when no draft yet)
     * @param list<FormPresenceParticipant> $presence the live, TTL-pruned editor roster
     * @param list<FormLock> $locks the live locks on the document (whole-form when
     *        {@see FormLock::$field} is null, per-field otherwise); empty for the
     *        lock-free modes. Drives the client's read-only / "locked by X" UX.
     */
    public function __construct(
        public string $scopeKey,
        public array $values,
        public int $version,
        public ?string $origin,
        public int $updatedAt,
        public array $presence,
        public FormCollaborationMode $mode,
        public array $locks = [],
    ) {}

    /**
     * The canonical single-document envelope a subscriber renders. `data` is the
     * shared record (the merged draft + its version/origin); `meta` carries the
     * collaboration projection (mode + presence roster) that drives the client's
     * presence chips and per-mode UX.
     *
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function toEnvelope(): array
    {
        $presence = [];
        foreach ($this->presence as $participant) {
            $presence[] = [
                'participantId' => $participant->participantId,
                'label'         => $participant->label,
                'role'          => $participant->role,
                'lastSeenAt'    => $participant->lastSeenAt,
            ];
        }

        $locks = [];
        foreach ($this->locks as $lock) {
            $locks[] = [
                'field'       => $lock->field,
                'holderId'    => $lock->holderId,
                'holderLabel' => $lock->holderLabel,
                'acquiredAt'  => $lock->acquiredAt,
            ];
        }

        return [
            'data' => [
                'values'    => $this->values,
                'version'   => $this->version,
                'origin'    => $this->origin,
                'updatedAt' => $this->updatedAt,
            ],
            'meta' => [
                'mode'     => $this->mode->value,
                'presence' => $presence,
                'locks'    => $locks,
            ],
        ];
    }
}
