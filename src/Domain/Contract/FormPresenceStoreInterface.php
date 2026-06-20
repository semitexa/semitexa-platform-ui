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
}
