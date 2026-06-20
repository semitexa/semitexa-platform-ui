<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Collaboration;

/**
 * Collaborative Form Data · Phase 2 — the immutable snapshot of a collaborative
 * form document's shared draft, as the store hands it to callers.
 *
 * Decouples consumers (the inbound handler, the document feed projector) from
 * the ORM resource: they read field values + version + last-writer audit
 * without touching {@see \Semitexa\PlatformUi\Application\Db\MySQL\Model\FormCollabDraftResource}.
 * `version` is the optimistic-concurrency coordinate: a save echoes the version
 * it read, and the store rejects it if the draft moved on.
 */
final readonly class FormCollabDraftState
{
    /**
     * @param array<string, scalar|null> $values field name → current value
     * @param int $updatedAt unix timestamp of the last write
     */
    public function __construct(
        public string $scopeKey,
        public array $values,
        public int $version,
        public ?string $updatedBy,
        public int $updatedAt,
    ) {}
}
