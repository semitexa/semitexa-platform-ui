<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Exception\AccessDeniedException;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Resource\JsonResourceResponse;
use Semitexa\PlatformUi\Application\Payload\Request\FormDocumentFeedPayload;
use Semitexa\PlatformUi\Application\Service\Collaboration\FormDocumentProjector;
use Semitexa\Ssr\Application\Handler\PayloadHandler\AbstractSseDocumentFeedHandler;
use Semitexa\Ssr\Domain\Contract\SseDocumentFeedPayloadInterface;
use Semitexa\Ssr\Domain\Model\FormDocumentScope;

/**
 * Collaborative Form Data · Phase 3 (Shared mode) — the concrete document feed
 * that closes the live broadcast loop on the server: `platform-ui.form-doc`,
 * subscribed through HUG onto each editor's KISS stream, re-projecting a collaborative document's shared state to
 * every editor whenever the inbound handler touches its `formdoc:{key}:{id}`
 * scope.
 *
 * The READ counterpart of {@see \Semitexa\PlatformUi\Application\Service\Collaboration\FormCollaborationEventHandler}:
 * that one mutates the stores and touches the scope; this one re-runs on the
 * touch and renders. The single seam below — {@see buildDocumentResponse()} —
 * is "verify the signed context, project the shared state, render the canonical
 * envelope"; everything else (the framed Track-R re-run on scope touch) is
 * inherited verbatim from
 * {@see AbstractSseDocumentFeedHandler}.
 *
 * Trust: the watched scope + mode come ONLY from the HMAC-verified signed
 * context token on the payload (see {@see FormDocumentFeedPayload}). A missing /
 * forged / expired token raises {@see AccessDeniedException} — which the feed
 * base propagates (never frames): HUG's admission refuses the subscribe, and a
 * re-run terminates it — exactly as on the HUG write path.
 */
#[AsPayloadHandler(payload: FormDocumentFeedPayload::class, resource: ResourceResponse::class)]
final class FormDocumentFeedHandler extends AbstractSseDocumentFeedHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected FormDocumentProjector $projector;

    /** Test seam — production path uses property injection. */
    public function withProjector(FormDocumentProjector $projector): self
    {
        $this->projector = $projector;
        return $this;
    }

    public function handle(
        FormDocumentFeedPayload $payload,
        JsonResourceResponse $response,
    ): JsonResourceResponse {
        return $this->serve($payload, $response);
    }

    protected function buildDocumentResponse(
        SseDocumentFeedPayloadInterface $payload,
        JsonResourceResponse $response,
    ): JsonResourceResponse {
        if (!$payload instanceof FormDocumentFeedPayload) {
            throw new \LogicException('Collaborative document feed serves FormDocumentFeedPayload only.');
        }

        $scope = $payload->scope();
        // An unverified token yields an empty scope; a verified-but-malformed
        // scope is tamper. Either way deny — an auth-shaped exception the base
        // propagates so no subscription is admitted for an untrusted caller.
        if ($payload->verifiedCfg() === null || !FormDocumentScope::isValid($scope)) {
            throw new AccessDeniedException('The collaborative document context is missing or invalid.');
        }

        $snapshot = $this->projector->project($scope, $payload->mode(), $payload->fields());

        // self::encode() is the feed base's canonical JSON encoder — the same
        // one every frame uses, so the envelope bytes never vary.
        $response->setStatusCode(HttpStatus::Ok->value);
        $response->setContent(self::encode($snapshot->toEnvelope()));

        return $response;
    }
}
