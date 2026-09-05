<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\PlatformUi\Domain\Model\CalendarEvent;
use Semitexa\PlatformUi\Application\Payload\Request\CalendarEventSavePayload;
use Semitexa\PlatformUi\Application\Service\CalendarEventView;
use Semitexa\PlatformUi\Domain\Contract\CalendarEventRepositoryInterface;

/**
 * Create or update a calendar event. The write goes through the repository so
 * the ORM auto-publishes `platform_calendar_events` invalidation and every open
 * feed re-runs live.
 */
#[AsPayloadHandler(payload: CalendarEventSavePayload::class, resource: ResourceResponse::class)]
final class CalendarEventSaveHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected CalendarEventRepositoryInterface $events;

    public function handle(CalendarEventSavePayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $now = new \DateTimeImmutable();
        $id = $payload->getId();
        $existing = $id !== '' ? $this->events->findById($id) : null;

        // A naive datetime at the boundary is interpreted in the configured
        // calendar zone (default UTC) rather than blindly as UTC, so a
        // direct/alternate API caller can't land the event at the wrong hour.
        $assume = CalendarEventSavePayload::defaultZone();

        $event = new CalendarEvent(
            id: $existing?->getId() ?? Uuid7::generate(),
            tenantId: $existing?->getTenantId(),
            userId: $existing !== null ? $existing->getUserId() : $payload->getUserId(),
            title: $payload->getTitle(),
            startsAt: $payload->getStartsAt($assume),
            endsAt: $payload->getEndsAt($assume),
            allDay: $payload->getAllDay() === 1,
            location: $payload->getLocation(),
            notes: $payload->getNotes(),
            color: $payload->getColor(),
            createdAt: $existing?->getCreatedAt() ?? $now,
            updatedAt: $now,
        );

        $existing !== null ? $this->events->update($event) : $this->events->insert($event);

        return $resource
            ->setContent((string) json_encode(
                ['ok' => true, 'event' => CalendarEventView::toArray($event)],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ))
            ->setHeader('Content-Type', 'application/json');
    }
}
