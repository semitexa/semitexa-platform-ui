<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Collaboration;

/**
 * A collaborative form's shared draft as the store holds it — identity,
 * version and all.
 *
 * Distinct from {@see FormCollabDraftState}, deliberately: that is the snapshot
 * handed to the handler and the feed projector, carrying only what a reader
 * needs. This is the row's business model, and the difference is `version`
 * plus the identity a write has to address.
 *
 * `version` is the optimistic-concurrency coordinate: a save echoes the version
 * it read, and the store rejects it if the draft moved on. Two people typing
 * into one form is the normal case here, not the exception.
 */
final readonly class FormCollabDraft
{
    /**
     * @param array<string, scalar|null> $values field name → current value
     */
    public function __construct(
        private string $id,
        private ?string $tenantId,
        private string $scopeKey,
        private array $values,
        private int $version,
        private ?string $updatedBy = null,
        private ?\DateTimeImmutable $updatedAt = null,
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }

    public function getScopeKey(): string
    {
        return $this->scopeKey;
    }

    /** @return array<string, scalar|null> */
    public function getValues(): array
    {
        return $this->values;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getUpdatedBy(): ?string
    {
        return $this->updatedBy;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** The snapshot readers get — no identity, no way to write back by accident. */
    public function toState(): FormCollabDraftState
    {
        return new FormCollabDraftState(
            scopeKey: $this->scopeKey,
            values: $this->values,
            version: $this->version,
            updatedBy: $this->updatedBy,
            updatedAt: ($this->updatedAt ?? new \DateTimeImmutable())->getTimestamp(),
        );
    }
}
