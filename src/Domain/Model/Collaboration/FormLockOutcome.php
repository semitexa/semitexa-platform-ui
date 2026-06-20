<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Collaboration;

/**
 * Collaborative Form Data · Phase 2 — the result of a lock acquire attempt:
 * whether THIS caller now holds the lock, plus the current holder either way
 * (self when acquired, the other party when denied) so the client can render a
 * "locked by X" banner without a second round-trip.
 */
final readonly class FormLockOutcome
{
    public function __construct(
        public bool $acquired,
        public FormLock $holder,
    ) {}

    /** Was the lock denied because someone else already holds it? */
    public function heldByOther(): bool
    {
        return !$this->acquired;
    }
}
