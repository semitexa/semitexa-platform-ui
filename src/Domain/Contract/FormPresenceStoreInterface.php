<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Contract;

use Semitexa\PlatformUi\Domain\Model\Collaboration\FormPresenceParticipant;

/**
 * Collaborative Form Data · Phase 2 — the ephemeral presence roster for one
 * collaborative form document, keyed by its `formdoc:{formKey}:{recordId}`
 * scope key.
 *
 * TTL-driven: a participant is present only while it heartbeats via
 * {@see ping()}; the store prunes entries older than its presence TTL on every
 * read so a silently-closed tab disappears without an explicit leave. Backed by
 * the shared cache (Redis in production) so the roster is consistent across
 * Swoole workers.
 */
interface FormPresenceStoreInterface
{
    /**
     * Heartbeat: upsert this participant with a fresh timestamp and return the
     * pruned roster (this participant included). Called on connect and on a
     * recurring client heartbeat.
     *
     * @return list<FormPresenceParticipant>
     */
    public function ping(string $scopeKey, string $participantId, string $label, string $role): array;

    /**
     * Explicit leave: drop this participant and return the pruned roster.
     *
     * @return list<FormPresenceParticipant>
     */
    public function leave(string $scopeKey, string $participantId): array;

    /**
     * The current pruned roster without touching this caller's presence.
     *
     * @return list<FormPresenceParticipant>
     */
    public function roster(string $scopeKey): array;

    /**
     * How long an implementation must remember a document's last heartbeat.
     * A draft older than this with no remembered heartbeat is abandoned for
     * certain; a younger one is not known to be.
     */
    public const LAST_SEEN_MEMORY_SECONDS = 86400;

    /**
     * When anyone last heartbeat in this document (unix time), or null when
     * the store DOES NOT KNOW: the mark aged out after
     * {@see LAST_SEEN_MEMORY_SECONDS}, the cache was flushed, or the read
     * failed (a shared cache answers an outage with a miss). Null never means
     * "nobody was here", and a caller must not act as if it did
     * ({@see \Semitexa\PlatformUi\Application\Service\Collaboration\FormCollabDraftRetention}).
     * Unlike the roster it outlives the presence TTL: it is what tells an
     * editor who closed a laptop lid for ten minutes from one who left.
     */
    public function lastSeenAt(string $scopeKey): ?int;
}
