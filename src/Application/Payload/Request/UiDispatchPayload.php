<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * Inbound route marker for the Platform UI HTTP dispatch endpoint.
 *
 * Why a separate path from SSR's /__ui/event:
 *   semitexa/ssr ships a foundation-layer placeholder at /__ui/event that
 *   accepts the framework-layer UiEventEnvelope shape (schemaVersion,
 *   eventId, correlationId, semanticEvent, signedContext, timestamp, …).
 *   That endpoint validates the envelope shape and the signed context,
 *   then returns 202 not_implemented. Platform UI's dispatcher uses a
 *   *minimal* {ctx, payload} body and bridges captured frontend events
 *   to declared #[UiOn] handlers — a layered concern that does not need
 *   the full framework-layer envelope. The two endpoints will be unified
 *   in a future framework-layer slice that introduces a
 *   UiInteractionDispatcherInterface contract; until then, the layering
 *   stays explicit and non-conflicting.
 *
 * Why ResourceResponse directly (no #[AsResource]-tagged subclass):
 *   The framework's response renderer reads `getRenderHandle()` from the
 *   resource. A handle-bearing resource triggers `renderJsonResponse()`,
 *   which re-encodes the resource's render context as the response body
 *   and overwrites whatever the handler set via setContent(). The
 *   dispatcher handler builds its own JSON envelope, so the resource
 *   must NOT carry a render handle — using ResourceResponse without
 *   #[AsResource] keeps the handler's setContent() body intact.
 *
 * Body parsing is done by the handler against the raw JSON body so
 * setter-based hydration can never silently drop a smuggled routing
 * field — the UiPayloadFieldGuard inside UiInteractionDispatcher walks
 * the whole payload tree.
 */
#[AsPublicPayload(
    path: '/__ui/dispatch',
    methods: ['POST'],
    responseWith: ResourceResponse::class,
    consumes: ['application/json'],
    produces: ['application/json'],
)]
final class UiDispatchPayload
{
}
