<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Exception;

use Semitexa\Core\Exception\ConflictException;

/**
 * Collaborative Form Data · Phase 2 — raised when an optimistic save echoes a
 * stale draft version (someone else committed first).
 *
 * Extends the framework {@see ConflictException} so it already maps to HTTP 409
 * via the ExceptionMapper; `getErrorContext()` surfaces both versions so the
 * Optimistic-mode client can offer keep-mine / take-theirs / merge. The default
 * `getErrorCode()` resolves to `form_draft_version_conflict`.
 */
final class FormDraftVersionConflictException extends ConflictException
{
    public function __construct(
        private readonly string $scopeKey,
        private readonly int $expectedVersion,
        private readonly int $currentVersion,
    ) {
        parent::__construct(sprintf(
            'Form draft "%s" was modified concurrently (expected v%d, found v%d).',
            $scopeKey,
            $expectedVersion,
            $currentVersion,
        ));
    }

    /** @return array<string, mixed> */
    public function getErrorContext(): array
    {
        return [
            'scopeKey'        => $this->scopeKey,
            'expectedVersion' => $this->expectedVersion,
            'currentVersion'  => $this->currentVersion,
        ];
    }
}
