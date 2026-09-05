<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model;

/**
 * One entry on a calendar.
 *
 * `allDay` is a boolean here and an integer column in the row — that widening
 * was leaking into every reader as `$event->all_day === 1`.
 */
final readonly class CalendarEvent
{
    public function __construct(
        private string $id,
        private ?string $tenantId,
        private ?string $userId,
        private string $title,
        private \DateTimeImmutable $startsAt,
        private \DateTimeImmutable $endsAt,
        private bool $allDay = false,
        private ?string $location = null,
        private ?string $notes = null,
        private ?string $color = null,
        private ?\DateTimeImmutable $createdAt = null,
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

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): \DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function isAllDay(): bool
    {
        return $this->allDay;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
