<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * `POST /platform/calendar/events/save` — create (no `id`) or update (with
 * `id`) a calendar event. On a separate path from the SSE feed so it never
 * collides with the feed's POST re-hydrate. Datetime strings are ISO-8601.
 */
#[AsPublicPayload(
    path: '/platform/calendar/events/save',
    methods: ['POST'],
    responseWith: ResourceResponse::class,
    consumes: ['application/json'],
    produces: ['application/json'],
)]
final class CalendarEventSavePayload implements ValidatablePayloadInterface
{
    private string $id = '';
    private string $title = '';
    private string $startsAt = '';
    private string $endsAt = '';
    private string $allDay = '0';
    private string $location = '';
    private string $notes = '';
    private string $color = '';
    private string $userId = '';

    /**
     * @return array<string, list<string>>
     */
    public function validate(): array
    {
        $errors = [];
        if (trim($this->title) === '') {
            $errors['title'][] = 'A title is required.';
        }
        if (self::parse($this->startsAt) === null) {
            $errors['startsAt'][] = 'A valid start date/time is required.';
        }
        if (self::parse($this->endsAt) === null) {
            $errors['endsAt'][] = 'A valid end date/time is required.';
        }

        return $errors;
    }

    public function getId(): string
    {
        return trim($this->id);
    }

    public function getTitle(): string
    {
        return trim($this->title);
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return self::parse($this->startsAt) ?? new \DateTimeImmutable();
    }

    public function getEndsAt(): \DateTimeImmutable
    {
        return self::parse($this->endsAt) ?? $this->getStartsAt();
    }

    public function getAllDay(): int
    {
        return ($this->allDay === '1' || strtolower($this->allDay) === 'true') ? 1 : 0;
    }

    public function getLocation(): ?string
    {
        $v = trim($this->location);

        return $v === '' ? null : $v;
    }

    public function getNotes(): ?string
    {
        $v = trim($this->notes);

        return $v === '' ? null : $v;
    }

    public function getColor(): ?string
    {
        $v = trim($this->color);

        return $v === '' ? null : $v;
    }

    public function getUserId(): ?string
    {
        $v = trim($this->userId);

        return $v === '' ? null : $v;
    }

    public function setId(string $v): void
    {
        $this->id = $v;
    }

    public function setTitle(string $v): void
    {
        $this->title = $v;
    }

    public function setStartsAt(string $v): void
    {
        $this->startsAt = $v;
    }

    public function setEndsAt(string $v): void
    {
        $this->endsAt = $v;
    }

    public function setAllDay(string $v): void
    {
        $this->allDay = $v;
    }

    public function setLocation(string $v): void
    {
        $this->location = $v;
    }

    public function setNotes(string $v): void
    {
        $this->notes = $v;
    }

    public function setColor(string $v): void
    {
        $this->color = $v;
    }

    public function setUserId(string $v): void
    {
        $this->userId = $v;
    }

    private static function parse(string $value): ?\DateTimeImmutable
    {
        if (trim($value) === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
