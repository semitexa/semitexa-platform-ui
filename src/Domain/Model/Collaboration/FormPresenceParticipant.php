<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Collaboration;

/**
 * Collaborative Form Data · Phase 2 — one participant currently present on a
 * collaborative form document, as the presence store reports it.
 *
 * Ephemeral (Redis/cache, TTL-driven): a participant stays in the roster only
 * while it keeps heartbeating; a tab that closes silently ages out when its
 * `lastSeenAt` passes the presence TTL.
 */
final readonly class FormPresenceParticipant
{
    public function __construct(
        public string $participantId,
        public string $label,
        public string $role,
        public int $lastSeenAt,
    ) {}
}
