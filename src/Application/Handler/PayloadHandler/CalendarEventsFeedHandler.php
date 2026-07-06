<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Resource\JsonResourceResponse;
use Semitexa\PlatformUi\Application\Payload\Request\CalendarEventsFeedPayload;
use Semitexa\PlatformUi\Application\Service\CalendarEventView;
use Semitexa\PlatformUi\Domain\Contract\CalendarEventRepositoryInterface;
use Semitexa\Ssr\Application\Handler\PayloadHandler\AbstractSseFeedHandler;
use Semitexa\Ssr\Domain\Contract\SseFeedPayloadInterface;
use Semitexa\Ssr\Application\Service\UiEvent\UiSseEventType;

/**
 * Serves the calendar events feed as a live `{data:[...], meta}` collection.
 * A plain GET degrades to a one-shot JSON pull; the SSE connect re-runs on
 * `platform_calendar_events` invalidation. All the held-open plumbing (stream
 * mint, Track-R re-run, JSON degrade) is inherited from {@see AbstractSseFeedHandler};
 * the only seam is {@see buildResponse()} — "query the window, encode the envelope".
 */
#[AsPayloadHandler(payload: CalendarEventsFeedPayload::class, resource: ResourceResponse::class)]
final class CalendarEventsFeedHandler extends AbstractSseFeedHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected CalendarEventRepositoryInterface $events;

    public function handle(CalendarEventsFeedPayload $payload, JsonResourceResponse $response): JsonResourceResponse
    {
        return $this->serve($payload, $response);
    }

    protected function buildResponse(SseFeedPayloadInterface $payload, JsonResourceResponse $response): JsonResourceResponse
    {
        if (!$payload instanceof CalendarEventsFeedPayload) {
            throw new \LogicException('Calendar events feed serves CalendarEventsFeedPayload only.');
        }

        [$from, $to] = self::resolveWindow($payload->getFrom(), $payload->getTo());
        $userId = $payload->getUserId();

        $rows = array_map(
            static fn ($event): array => CalendarEventView::toArray($event),
            $this->events->findInRange($from, $to, $userId),
        );

        $response->setStatusCode(HttpStatus::Ok->value);
        $response->setContent(self::encode([
            'data' => $rows,
            'meta' => [
                'from' => $from->format('c'),
                'to' => $to->format('c'),
                'count' => count($rows),
            ],
        ]));

        return $response;
    }

    protected function successEventType(): UiSseEventType
    {
        return UiSseEventType::UiCollectionData;
    }

    protected function errorEventType(): UiSseEventType
    {
        return UiSseEventType::UiCollectionError;
    }

    /**
     * Resolve the [from, to] window; default to the current month padded to a
     * 6-week grid when the client sends nothing usable.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private static function resolveWindow(string $from, string $to): array
    {
        $fromDt = self::parse($from) ?? new \DateTimeImmutable('first day of this month 00:00:00');
        $toDt = self::parse($to) ?? $fromDt->modify('+42 days');
        if ($toDt < $fromDt) {
            $toDt = $fromDt->modify('+42 days');
        }

        return [$fromDt, $toDt];
    }

    private static function parse(string $value): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
