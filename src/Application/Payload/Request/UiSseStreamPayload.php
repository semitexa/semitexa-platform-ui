<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * GET /__ui/stream?token=<signed-channel-token>
 *
 * Long-lived Server-Sent Events stream that delivers UiResponsePatch
 * messages to a single browser tab. The token is verified against
 * UiSseChannelToken's purpose claim before any stream lifecycle starts.
 *
 * Transport: TransportType::Sse — the framework knows not to apply the
 * standard response-rendering pipeline because the handler will write
 * to the underlying Swoole response object directly.
 */
#[AsPublicPayload(
    responseWith: ResourceResponse::class,
    path: '/__ui/stream',
    methods: ['GET'],
    transport: TransportType::Sse,
    produces: ['text/event-stream'],
)]
final class UiSseStreamPayload
{
}
