<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model;

/**
 * One submission recorded by the form playground: which instance, which action,
 * and the values that were sent.
 */
final readonly class UiFormDemoSubmission
{
    /**
     * @param array<string, mixed> $values the action's payload as submitted
     */
    public function __construct(
        private string $id,
        private string $formInstanceId,
        private string $actionName,
        private \DateTimeImmutable $submittedAt,
        private array $values = [],
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getFormInstanceId(): string
    {
        return $this->formInstanceId;
    }

    public function getActionName(): string
    {
        return $this->actionName;
    }

    public function getSubmittedAt(): \DateTimeImmutable
    {
        return $this->submittedAt;
    }

    /** @return array<string, mixed> */
    public function getValues(): array
    {
        return $this->values;
    }
}
