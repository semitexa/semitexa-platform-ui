<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Attribute\LiveFilterParam;
use Semitexa\Core\Attribute\SseGateModel;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Core\Attribute\WatchScopes;
use Semitexa\Core\Request;
use Semitexa\Core\Resource\JsonResourceResponse;
use Semitexa\Core\Resource\RenderProfile;
use Semitexa\Ssr\Domain\Contract\SseFeedPayloadInterface;

/**
 * `GET|POST /platform/calendar/events` — the held-open SSE feed of calendar
 * events in a date window. Plain GET (no SSE Accept) degrades to a one-shot
 * `{data:[...], meta}` JSON pull; the SSE connect re-runs whenever the
 * `platform_calendar_events` scope is invalidated (any event create/update/
 * delete auto-publishes it), so all views/tabs stay live.
 *
 * Domain params (`from`/`to`/`userId`) are the feed's own `#[LiveFilterParam]`
 * fields — only they are applied by a re-hydrate intake; `streamId` / the HTTP
 * request are transport metadata, never filters.
 */
#[AsPublicPayload(
    path: '/platform/calendar/events',
    methods: ['GET', 'POST'],
    responseWith: JsonResourceResponse::class,
    renderProfile: RenderProfile::Json,
    transport: TransportType::Sse,
    sseGateModel: SseGateModel::BearerSession,
)]
#[WatchScopes('platform_calendar_events')]
final class CalendarEventsFeedPayload implements SseFeedPayloadInterface
{
    #[LiveFilterParam]
    private ?string $from = null;

    #[LiveFilterParam]
    private ?string $to = null;

    #[LiveFilterParam]
    private ?string $userId = null;

    private ?string $streamId = null;

    private ?Request $httpRequest = null;

    public function setHttpRequest(Request $request): void
    {
        $this->httpRequest = $request;
    }

    public function getHttpRequest(): ?Request
    {
        return $this->httpRequest;
    }

    public function setStreamId(?string $streamId): void
    {
        $this->streamId = self::trimToNull($streamId);
    }

    public function getStreamId(): ?string
    {
        return $this->streamId;
    }

    public function getFrom(): string
    {
        return $this->from ?? '';
    }

    public function setFrom(string $v): void
    {
        $this->from = self::trimToNull($v);
    }

    public function getTo(): string
    {
        return $this->to ?? '';
    }

    public function setTo(string $v): void
    {
        $this->to = self::trimToNull($v);
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function setUserId(string $v): void
    {
        $this->userId = self::trimToNull($v);
    }

    /**
     * @return array<string, mixed>
     */
    public function toViewParams(): array
    {
        return [
            'from' => $this->from ?? '',
            'to' => $this->to ?? '',
            'userId' => $this->userId ?? '',
            'streamId' => $this->streamId ?? '',
        ];
    }

    private static function trimToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
