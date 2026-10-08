<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Resource\JsonResourceResponse;
use Semitexa\PlatformUi\Application\Payload\Request\IslandFeedPayload;
use Semitexa\Ssr\Application\Handler\PayloadHandler\AbstractSseFeedHandler;
use Semitexa\Ssr\Application\Service\Component\ComponentRenderer;
use Semitexa\Ssr\Application\Service\UiEvent\UiSseEventType;
use Semitexa\Ssr\Domain\Contract\SseFeedPayloadInterface;

/**
 * Serves an island's live feed: `{data: {instance, html}}`, the component
 * drawn now for this visitor under the same instance id, so the page morphs
 * it in place. The held-open plumbing is AbstractSseFeedHandler's.
 */
#[AsPayloadHandler(payload: IslandFeedPayload::class, resource: ResourceResponse::class)]
final class IslandFeedHandler extends AbstractSseFeedHandler implements TypedHandlerInterface
{
    public function handle(IslandFeedPayload $payload, JsonResourceResponse $response): JsonResourceResponse
    {
        return $this->serve($payload, $response);
    }

    protected function buildResponse(SseFeedPayloadInterface $payload, JsonResourceResponse $response): JsonResourceResponse
    {
        $island = $payload instanceof IslandFeedPayload ? $payload->island() : null;
        if ($island === null) {
            $response->setStatusCode(HttpStatus::UnprocessableEntity->value);
            $response->setContent(self::encode(['ok' => false, 'reason' => 'The token names no island.']));

            return $response;
        }

        if ($payload instanceof IslandFeedPayload && $payload->takeDrawn()) {
            // The subscribe's first frame: the page shows this render already.
            // Answer without drawing it again; the next write draws.
            $response->setStatusCode(HttpStatus::Ok->value);
            $response->setContent(self::encode(['data' => ['instance' => $island['instance']]]));

            return $response;
        }

        $html = ComponentRenderer::render(
            $island['component'],
            ['instanceId' => $island['instance']] + $island['props'],
            forceImmediateRender: true,
        );
        $response->setStatusCode(HttpStatus::Ok->value);
        $response->setContent(self::encode(['data' => ['instance' => $island['instance'], 'html' => $html]]));

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
}
