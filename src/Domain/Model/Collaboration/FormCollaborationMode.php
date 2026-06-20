<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Collaboration;

/**
 * Collaborative Form Data · Phase 1 — the concurrency stance a collaborative
 * form runs under. Declared via {@see \Semitexa\PlatformUi\Attribute\CollaborativeForm},
 * enforced by the inbound collaboration handler (server-side policy), and
 * mirrored to the client (which renders presence/lock affordances and decides
 * whether a remote field delta is applied).
 *
 * The four modes form an escalation ladder of collaboration coupling:
 *   - {@see Optimistic} — no live coupling; free local editing, conflict caught
 *     at save time by a document version/etag guard. The safe baseline; works
 *     even with the SSE stream absent.
 *   - {@see Shared} — every field edit broadcasts to all editors (last-write-
 *     wins per field) and a presence roster is live. Co-editing.
 *   - {@see FormLock} — the first editor holds an exclusive whole-form lock;
 *     others are read-only with a live view until the lock releases/expires.
 *   - {@see FieldLock} — co-editing as Shared, but each field is exclusively
 *     held while someone is editing it (no two cursors in one field).
 */
enum FormCollaborationMode: string
{
    case Optimistic = 'optimistic';
    case Shared     = 'shared';
    case FormLock   = 'form-lock';
    case FieldLock  = 'field-lock';

    /** The default stance when a form opts into collaboration without naming a mode. */
    public static function default(): self
    {
        return self::Optimistic;
    }

    /** Does this mode broadcast individual field edits to other editors live? */
    public function broadcastsFieldEdits(): bool
    {
        return $this === self::Shared || $this === self::FieldLock;
    }

    /** Does this mode take an exclusive lock (whole-form or per-field)? */
    public function usesLock(): bool
    {
        return $this === self::FormLock || $this === self::FieldLock;
    }

    /** Does this mode rely on a save-time version guard rather than live locking? */
    public function isOptimistic(): bool
    {
        return $this === self::Optimistic;
    }
}
