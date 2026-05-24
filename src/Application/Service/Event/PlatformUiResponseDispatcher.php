<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

use Closure;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldRuleRegistryInterface;
use Semitexa\PlatformUi\Contract\UiEventHandlerInterface;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionException;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionResult;
use Semitexa\Ssr\Application\Service\UiEvent\CanonicalUiMessagePublisherInterface;
use Semitexa\Ssr\Application\Service\UiEvent\UiComponentStateMessage;
use Semitexa\Ssr\Application\Service\UiEvent\UiEventEnvelope;
use Semitexa\Ssr\Application\Service\UiEvent\UiPatchMessage;
use Semitexa\Ssr\Application\Service\UiEvent\UiResponseDispatcherInterface;
use Semitexa\Ssr\Application\Service\UiEvent\UiResponseDispatchResult;
use Throwable;

/**
 * Platform-UI binding of {@see UiResponseDispatcherInterface} — the
 * concrete `#[SatisfiesServiceContract]` winner that routes canonical
 * `POST /__ui/event` traffic through the existing
 * {@see UiInteractionDispatcher} pipeline (Phase 3 of the
 * `packages/semitexa-platform-ui/docs/transport-architecture.md`
 * ADR-0001, back-end portion only).
 *
 * Why this is an adapter rather than a parallel implementation:
 *
 *   - {@see UiInteractionDispatcher} is the hardened production pipeline
 *     (payload-field guard → signed-context verification → registry
 *     resolution → replay-claim → authorizer → handler invocation →
 *     patch validation). Duplicating any one of those steps would
 *     create a security divergence between the two inbound endpoints —
 *     the explicit non-goal of this slice.
 *   - The adapter therefore performs *only the shape translation*
 *     between the canonical envelope and the legacy 3-tuple, then
 *     delegates.
 *
 * Shape translation (envelope → legacy args):
 *
 *   | canonical                        | legacy                |
 *   | -------------------------------- | --------------------- |
 *   | `$envelope->signedContext`       | `$ctx`                |
 *   | `$envelope->eventId`             | `$dispatchId`         |
 *   | `$envelope->payload`             | `$payload`            |
 *
 * `eventId` and `dispatchId` are the same semantic concept — a per-
 * attempt unique replay-protection identity. The names differ because
 * one was introduced by the canonical envelope contract and the other
 * by the legacy dispatcher. The downstream dispatcher's strict pattern
 * (`[A-Za-z0-9][A-Za-z0-9_-]{4,127}`) is enforced; an `eventId` that
 * doesn't match becomes a clean `invalid_dispatch_id` rejection rather
 * than an internal error.
 *
 * Signed-context double-verification:
 *
 *   The framework endpoint pre-verifies the signed context before
 *   calling `dispatch()`, and {@see UiInteractionDispatcher} re-verifies
 *   it as step 4 of its hardened pipeline. The contract docblock on
 *   {@see UiResponseDispatcherInterface} discourages re-verification on
 *   "skew bugs" grounds, but both verifications call the same
 *   {@see \Semitexa\Ssr\Application\Service\UiEvent\SignedContext::verify()}
 *   on the same blob — they return byte-identical claims within a
 *   request lifetime, and `SignedContext::verify()` is pure. The
 *   alternative — adding a `dispatchVerified(...)` bypass entrypoint to
 *   `UiInteractionDispatcher` — widens the dispatcher's public surface
 *   for negligible gain. We accept the microsecond-cheap second
 *   verification.
 *
 * Result mapping:
 *
 *   - Successful {@see UiInteractionResult} → `UiResponseDispatchResult`
 *     with `statusCode=200`, `status='accepted'`, `phase='dispatch'`,
 *     `reason='accepted'`, body carrying `kind`, `patches[]`, `debug`,
 *     and `dispatchId`. The canonical envelope keys (eventId,
 *     correlationId, semanticEvent, schemaVersion, signedContext) come
 *     from the endpoint's own composer — the adapter MUST NOT echo them
 *     into `body` (endpoint allow-list would drop them anyway).
 *   - Typed {@see UiInteractionException} → `UiResponseDispatchResult`
 *     with the exception's `httpStatus` / `reason` / safe `message`.
 *     5xx codes map to `status='error'`; 4xx codes to `status='rejected'`.
 *   - Any other `\Throwable` is re-thrown; the framework endpoint
 *     catches it and emits the canonical `ui_event_dispatcher_failure`
 *     envelope.
 *
 * What this adapter MUST NOT leak in `body`:
 *
 *   - signedContext blob, raw claims, csrf tokens, replay keys
 *   - FQCNs, file paths, line numbers, stack traces
 *   - environment variable names, framework internals
 *
 * The legacy `/__ui/dispatch` endpoint remains unchanged in this slice.
 * The frontend runtime ({@see /__ui/dispatch} consumer in
 * `event-runtime.js`) has not been repointed.
 */
#[SatisfiesServiceContract(of: UiResponseDispatcherInterface::class)]
final class PlatformUiResponseDispatcher implements UiResponseDispatcherInterface
{
    /**
     * Safe identifier shape for the `sub` claim. Same family as the
     * pattern {@see UiEventManifestBuilder} enforces at mint time —
     * re-validated here defensively. A `sub` value that doesn't match
     * is treated as absent (publish path skipped, inline patches
     * returned).
     */
    private const SUBSCRIBER_CHANNEL_ID_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9_-]{0,127}\z/';

    #[InjectAsReadonly]
    protected UiReplayStoreInterface $replayStore;

    #[InjectAsReadonly]
    protected UiInteractionAuthorizerInterface $authorizer;

    #[InjectAsReadonly]
    protected UiFieldRuleRegistryInterface $ruleRegistry;

    #[InjectAsReadonly]
    protected CanonicalUiMessagePublisherInterface $publisher;

    /**
     * PSR-11 container used by {@see self::buildHandlerResolver()} to
     * resolve class-level #[HandlesUiEvent] service handlers by FQCN
     * at dispatch time — same seam as
     * {@see \Semitexa\PlatformUi\Application\Handler\PayloadHandler\UiDispatchHandler}
     * uses for the legacy `/__ui/dispatch` endpoint. Without this
     * propagation the canonical `/__ui/event` route would fall back
     * to the dispatcher's null-resolver branch and emit
     * `ui_handler_resolver_missing` 422 for every service-handler
     * dispatch — a divergence between the two endpoints that the
     * adapter exists specifically to prevent.
     */
    #[InjectAsReadonly]
    protected ContainerInterface $container;

    public function dispatch(UiEventEnvelope $envelope, array $verifiedClaims): UiResponseDispatchResult
    {
        // The framework endpoint already verified the signed ctx, and
        // the legacy dispatcher will re-verify (see class docblock).
        // We extract the optional canonical subscriber channel id from
        // the pre-verified claims here — same blob, same deterministic
        // verifier, so the value is byte-identical to what the legacy
        // dispatcher's claims hold.
        $subscriberChannelId = $this->extractSubscriberChannelId($verifiedClaims);
        // The target component instance id (`i` claim) addresses the
        // SSE `ui.componentState` frame to a single grid/component on the
        // page-session channel. Same blob, same deterministic verifier as
        // the legacy dispatcher reads at step 4 (`stringClaim($claims, 'i')`).
        $instanceId = $this->extractInstanceId($verifiedClaims);

        $dispatcher = $this->resolveLegacyDispatcher();

        try {
            $result = $dispatcher->dispatch(
                ctx:        $envelope->signedContext,
                dispatchId: $envelope->eventId,
                payload:    $envelope->payload,
            );
        } catch (UiInteractionException $e) {
            return $this->translateFailure($e, $envelope->eventId);
        }

        return $this->translateSuccess(
            $result,
            $envelope->eventId,
            $envelope->correlationId,
            $subscriberChannelId,
            $instanceId,
        );
    }

    /**
     * Extract the canonical SSE subscriber channel id from verified
     * claims. Returns `null` when the claim is absent, empty, or has
     * an unsafe shape — the caller falls back to inline patches in
     * that case.
     *
     * @param array<string, mixed> $verifiedClaims
     */
    private function extractSubscriberChannelId(array $verifiedClaims): ?string
    {
        $sub = $verifiedClaims['sub'] ?? null;
        if (!is_string($sub) || $sub === '') {
            return null;
        }
        if (preg_match(self::SUBSCRIBER_CHANNEL_ID_PATTERN, $sub) !== 1) {
            return null;
        }
        return $sub;
    }

    /**
     * Extract the target component instance id (`i` claim) from verified
     * claims. Returns `null` when the claim is absent, empty, or has an
     * unsafe shape — the caller then skips the state-publish branch and
     * leaves the state inline in `debug` (no instance to address the
     * `ui.componentState` frame to). The id shares the safe alphabet the
     * manifest builder mints (`uci_<hex>` matches the channel pattern).
     *
     * @param array<string, mixed> $verifiedClaims
     */
    private function extractInstanceId(array $verifiedClaims): ?string
    {
        $instance = $verifiedClaims['i'] ?? null;
        if (!is_string($instance) || $instance === '') {
            return null;
        }
        if (preg_match(self::SUBSCRIBER_CHANNEL_ID_PATTERN, $instance) !== 1) {
            return null;
        }
        return $instance;
    }

    private function resolveLegacyDispatcher(): UiInteractionDispatcher
    {
        // Production wiring resolves UiDispatchHandler through the container
        // and inherits the same CacheBackedUiReplayStore / authorizer / rule
        // registry winners via SatisfiesServiceContract. Mirror that here so
        // both inbound endpoints (`/__ui/event` and `/__ui/dispatch`) share
        // a single dispatcher configuration — including the closure-based
        // resolver for class-level #[HandlesUiEvent] service handlers
        // (Phase 5).
        return new UiInteractionDispatcher(
            payloadGuard:   new UiPayloadFieldGuard(),
            patchValidator: new UiPatchValidator(),
            replayStore:    $this->resolveReplayStore(),
            authorizer:     $this->resolveAuthorizer(),
            ruleRegistry:   isset($this->ruleRegistry) ? $this->ruleRegistry : null,
            handlerResolver: $this->buildHandlerResolver(),
        );
    }

    /**
     * @return Closure(class-string<UiEventHandlerInterface>): UiEventHandlerInterface|null
     */
    private function buildHandlerResolver(): ?Closure
    {
        if (!isset($this->container)) {
            return null;
        }

        $container = $this->container;
        return static function (string $fqcn) use ($container): UiEventHandlerInterface {
            $service = $container->get($fqcn);
            if (!$service instanceof UiEventHandlerInterface) {
                throw new \RuntimeException(sprintf(
                    'Service %s resolved by the container is not a UiEventHandlerInterface.',
                    $fqcn,
                ));
            }
            return $service;
        };
    }

    private function resolveReplayStore(): UiReplayStoreInterface
    {
        if (isset($this->replayStore)) {
            return $this->replayStore;
        }

        return new InMemoryUiReplayStore();
    }

    private function resolveAuthorizer(): UiInteractionAuthorizerInterface
    {
        if (isset($this->authorizer)) {
            return $this->authorizer;
        }

        return new AllowAllUiInteractionAuthorizer();
    }

    private function translateSuccess(
        UiInteractionResult $result,
        string $dispatchId,
        string $correlationId,
        ?string $subscriberChannelId,
        ?string $instanceId,
    ): UiResponseDispatchResult {
        $inlinePatches = [];
        foreach ($result->patches as $patch) {
            $inlinePatches[] = $patch->toJsonShape();
        }

        $streamedPatchCount = 0;
        // When the verified ctx carried a `sub` claim AND the canonical
        // KISS publisher is wired in (production: always; tests can
        // skip injection to exercise the inline-fallback path), each
        // generated patch is forwarded as a typed `ui.patch` message
        // over `/__semitexa_kiss`. Inline patches are then dropped to
        // avoid double-apply on the client.
        if ($subscriberChannelId !== null && $inlinePatches !== [] && isset($this->publisher)) {
            $publisher = $this->publisher;
            $publishedAll = true;
            $correlation = $correlationId !== '' ? $correlationId : null;
            foreach ($result->patches as $patch) {
                try {
                    $publisher->publish(
                        $subscriberChannelId,
                        new UiPatchMessage(
                            componentInstanceId: $patch->targetInstance,
                            patch:               $patch->toJsonShape(),
                            correlationId:       $correlation,
                        ),
                    );
                    $streamedPatchCount++;
                } catch (Throwable $publishFailure) {
                    // A publisher failure must NOT escape the dispatcher
                    // (the framework endpoint would map it to a generic
                    // `ui_event_dispatcher_failure`). Operator gets a
                    // breadcrumb via the framework logger; the client
                    // still receives the patch via the inline fallback
                    // below.
                    StaticLoggerBridge::error('platform_ui', 'Canonical UI patch publish failed', [
                        'exception_class'     => $publishFailure::class,
                        'exception_message'   => $publishFailure->getMessage(),
                        'subscriber_channel'  => $subscriberChannelId,
                        'dispatch_id'         => $dispatchId,
                    ]);
                    $publishedAll = false;
                    break;
                }
            }
            if ($publishedAll) {
                $inlinePatches = [];
            } else {
                // Partial publish — fall back to inline only. The few
                // patches that already reached the stream get re-applied
                // from the inline batch (idempotent for the safe
                // `setText` / `setValue` / `setAttribute` op set).
                $streamedPatchCount = 0;
            }
        }

        // --- State delivery over SSE (Phase 6) ---------------------------
        // Component handlers that return a whole-state snapshot (e.g. the
        // grid's resolved row bag) surface it via `debug['state']` rather
        // than the narrow setText/setValue/setAttribute patch op set
        // (see UiInteractionDispatchAdapter). When the page opted into the
        // canonical KISS channel (`sub` claim present) and we know the
        // target component instance (`i` claim), the state is the data
        // payload — so we publish it as a typed `ui.componentState` frame
        // and REMOVE it from the HTTP body. The HTTP response then carries
        // only the bare ack; the client renders from the SSE frame, matched
        // by componentInstanceId + correlationId (page-session channel +
        // correlationId routing). State is semantically a whole-component
        // replacement, not a DOM patch, so it gets its own branch rather
        // than being forced through the patch model above.
        $debug = $result->debug;
        $streamedStateCount = 0;
        if ($subscriberChannelId !== null
            && $instanceId !== null
            && isset($this->publisher)
            && isset($debug['state'])
            && is_array($debug['state'])
            && $debug['state'] !== []
        ) {
            try {
                $this->publisher->publish(
                    $subscriberChannelId,
                    new UiComponentStateMessage(
                        componentInstanceId: $instanceId,
                        state:               $debug['state'],
                        correlationId:       $correlationId !== '' ? $correlationId : null,
                    ),
                );
                // Published successfully — drop the state from the HTTP
                // body so the wire response is a bare ack. The client
                // consumes the data exclusively from the SSE frame.
                unset($debug['state']);
                $streamedStateCount = 1;
            } catch (Throwable $publishFailure) {
                // A publisher failure must NOT escape the dispatcher (the
                // framework endpoint would map it to a generic
                // `ui_event_dispatcher_failure`). Operator gets a
                // breadcrumb; the state stays inline in `debug` so the
                // client can still render via the legacy fallback path.
                StaticLoggerBridge::error('platform_ui', 'Canonical UI component-state publish failed', [
                    'exception_class'    => $publishFailure::class,
                    'exception_message'  => $publishFailure->getMessage(),
                    'subscriber_channel' => $subscriberChannelId,
                    'instance_id'        => $instanceId,
                    'dispatch_id'        => $dispatchId,
                ]);
                $streamedStateCount = 0;
            }
        }

        $body = [
            'kind'       => $result->kind,
            'patches'    => $inlinePatches,
            'debug'      => $debug,
            'dispatchId' => $dispatchId,
        ];
        if ($streamedPatchCount > 0) {
            $body['streamedPatchCount'] = $streamedPatchCount;
        }
        if ($streamedStateCount > 0) {
            // Mirrors `streamedPatchCount` — lets the frontend's
            // drain-on-demand opener know a frame is waiting on the
            // channel even when no inline patches were produced.
            $body['streamedStateCount'] = $streamedStateCount;
        }

        return new UiResponseDispatchResult(
            statusCode: 200,
            status:     'accepted',
            phase:      'dispatch',
            reason:     'accepted',
            message:    'UI interaction dispatched.',
            body:       $body,
        );
    }

    private function translateFailure(UiInteractionException $e, string $dispatchId): UiResponseDispatchResult
    {
        return new UiResponseDispatchResult(
            statusCode: $e->httpStatus,
            status:     $e->httpStatus >= 500 ? 'error' : 'rejected',
            phase:      'dispatch',
            reason:     $e->reason,
            message:    $e->getMessage(),
            body:       ['dispatchId' => $dispatchId],
        );
    }

    /**
     * Test seam — InjectAsReadonly does not run in unit tests. Production
     * code MUST NOT call these methods; the container fills the properties.
     */
    public function withReplayStore(UiReplayStoreInterface $replayStore): self
    {
        $this->replayStore = $replayStore;
        return $this;
    }

    public function withAuthorizer(UiInteractionAuthorizerInterface $authorizer): self
    {
        $this->authorizer = $authorizer;
        return $this;
    }

    public function withRuleRegistry(UiFieldRuleRegistryInterface $ruleRegistry): self
    {
        $this->ruleRegistry = $ruleRegistry;
        return $this;
    }

    public function withPublisher(CanonicalUiMessagePublisherInterface $publisher): self
    {
        $this->publisher = $publisher;
        return $this;
    }

    public function withContainer(ContainerInterface $container): self
    {
        $this->container = $container;
        return $this;
    }
}
