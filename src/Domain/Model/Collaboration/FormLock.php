<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Collaboration;

/**
 * Collaborative Form Data · Phase 2 — a held lock on a collaborative form
 * document (whole-form when `$field` is null, per-field otherwise).
 *
 * Ephemeral (cache, TTL-driven): a lock survives only while its holder
 * heartbeats; an abandoned lock ages out, which is exactly how takeover works
 * — once the previous holder stops renewing, the next acquirer succeeds.
 */
final readonly class FormLock
{
    public function __construct(
        public string $scopeKey,
        public ?string $field,
        public string $holderId,
        public string $holderLabel,
        public int $acquiredAt,
    ) {}

    public function isWholeForm(): bool
    {
        return $this->field === null;
    }
}
