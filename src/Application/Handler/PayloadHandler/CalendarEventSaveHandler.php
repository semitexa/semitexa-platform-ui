<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\PlatformUi\Application\Db\MySQL\Model\CalendarEventResource;
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

        $event = new CalendarEventResource(
            id: $existing?->id ?? Uuid7::generate(),
            tenant_id: $existing?->tenant_id,
            user_id: $existing !== null ? $existing->user_id : $payload->getUserId(),
            title: $payload->getTitle(),
            starts_at: $payload->getStartsAt($assume),
            ends_at: $payload->getEndsAt($assume),
            all_day: $payload->getAllDay(),
            location: $payload->getLocation(),
            notes: $payload->getNotes(),
            color: $payload->getColor(),
            created_at: $existing?->created_at ?? $now,
            updated_at: $now,
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
