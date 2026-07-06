<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Environment;
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
        if (self::parse($this->startsAt, new \DateTimeZone('UTC')) === null) {
            $errors['startsAt'][] = 'A valid start date/time is required.';
        }
        if (self::parse($this->endsAt, new \DateTimeZone('UTC')) === null) {
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

    /**
     * The event start as a UTC instant. A datetime string carrying an explicit
     * offset/Z is authoritative; a NAIVE local string ("2026-07-05T18:00") is
     * interpreted in $assume (default UTC), so a caller that does not go
     * through the JS client's toISOString() no longer silently lands the event
     * at the wrong hour. Correctness thus lives at the server boundary, not
     * only in the browser.
     */
    public function getStartsAt(?\DateTimeZone $assume = null): \DateTimeImmutable
    {
        $zone = $assume ?? new \DateTimeZone('UTC');

        return self::parse($this->startsAt, $zone) ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getEndsAt(?\DateTimeZone $assume = null): \DateTimeImmutable
    {
        return self::parse($this->endsAt, $assume ?? new \DateTimeZone('UTC')) ?? $this->getStartsAt($assume);
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

    /**
     * Parse a datetime to a UTC instant. Passing $assume as the constructor's
     * timezone means a NAIVE string is read in that zone, while a string that
     * carries its own offset/Z ignores $assume (PHP: explicit offset wins) —
     * so both the JS-client `...Z` path and a direct naive-local caller are
     * handled correctly, then normalised to UTC for storage.
     */
    private static function parse(string $value, \DateTimeZone $assume): ?\DateTimeImmutable
    {
        if (trim($value) === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value, $assume))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The zone a NAIVE calendar datetime is assumed to be in, from
     * `SEMITEXA_CALENDAR_DEFAULT_TZ` (default UTC — the current contract, since
     * the browser client always sends UTC `...Z`). An operator whose direct-API
     * callers submit local wall-clock sets this to their zone. Invalid values
     * fall back to UTC rather than throwing at the request boundary.
     */
    public static function defaultZone(): \DateTimeZone
    {
        $raw = trim((string) Environment::getEnvValue('SEMITEXA_CALENDAR_DEFAULT_TZ', 'UTC'));
        if ($raw === '') {
            return new \DateTimeZone('UTC');
        }
        try {
            return new \DateTimeZone($raw);
        } catch (\Throwable) {
            return new \DateTimeZone('UTC');
        }
    }
}
