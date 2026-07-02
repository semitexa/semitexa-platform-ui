<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * `POST /platform/calendar/events/delete` — remove a calendar event by id.
 */
#[AsPublicPayload(
    path: '/platform/calendar/events/delete',
    methods: ['POST'],
    responseWith: ResourceResponse::class,
    consumes: ['application/json'],
    produces: ['application/json'],
)]
final class CalendarEventDeletePayload implements ValidatablePayloadInterface
{
    private string $id = '';

    /**
     * @return array<string, list<string>>
     */
    public function validate(): array
    {
        $errors = [];
        if (trim($this->id) === '') {
            $errors['id'][] = 'An event id is required.';
        }

        return $errors;
    }

    public function getId(): string
    {
        return trim($this->id);
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }
}
