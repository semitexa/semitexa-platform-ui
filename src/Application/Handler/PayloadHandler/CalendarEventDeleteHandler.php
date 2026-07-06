<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\PlatformUi\Application\Payload\Request\CalendarEventDeletePayload;
use Semitexa\PlatformUi\Domain\Contract\CalendarEventRepositoryInterface;

/**
 * Delete a calendar event by id. The write auto-publishes
 * `platform_calendar_events` invalidation so open feeds re-run live.
 */
#[AsPayloadHandler(payload: CalendarEventDeletePayload::class, resource: ResourceResponse::class)]
final class CalendarEventDeleteHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected CalendarEventRepositoryInterface $events;

    public function handle(CalendarEventDeletePayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $this->events->deleteById($payload->getId());

        return $resource
            ->setContent((string) json_encode(['ok' => true], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->setHeader('Content-Type', 'application/json');
    }
}
