<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Collaboration;

use Semitexa\PlatformUi\Domain\Model\Collaboration\FormCollaborationMode;
use Semitexa\PlatformUi\Domain\Model\Collaboration\FormLock;

/**
 * Collaborative Form Data · Phase 3 — the server-side concurrency policy: given
 * a mode and the current lock state, decide whether an actor may edit a field.
 *
 * Pure and deterministic (no I/O) so it is trivially testable and the inbound
 * handler stays a thin orchestrator. The per-mode UX (when the client emits
 * focus/lock events, how it renders a denied edit) is the client runtime's job;
 * the authoritative gate lives here.
 */
final class FormCollaborationPolicy
{
    /**
     * May `$actorId` write `$field` right now?
     *
     *   - Optimistic / Shared: always (no live lock; Optimistic relies on the
     *     save-time version guard, Shared on last-write-wins merge).
     *   - FormLock: only the holder of the whole-form lock.
     *   - FieldLock: only the holder of THIS field's lock.
     */
    public static function canEditField(
        FormCollaborationMode $mode,
        ?FormLock $formLock,
        ?FormLock $fieldLock,
        string $actorId,
    ): bool {
        return match ($mode) {
            FormCollaborationMode::Optimistic,
            FormCollaborationMode::Shared    => true,
            FormCollaborationMode::FormLock  => $formLock !== null && $formLock->holderId === $actorId,
            FormCollaborationMode::FieldLock => $fieldLock !== null && $fieldLock->holderId === $actorId,
        };
    }
}
