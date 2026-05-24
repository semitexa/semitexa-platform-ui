<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Semitexa\PlatformUi\Application\Component\Builtin\FormComponent;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Semitexa\PlatformUi\Application\Service\Event\InMemoryUiReplayStore;
use Semitexa\PlatformUi\Application\Service\Event\PlatformUiResponseDispatcher;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\FormRootPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveRegistry;
use Semitexa\PlatformUi\Application\Service\Submit\SignedContextOnlyUiFormSubmitSecurityPolicy;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitSecurityPolicy;
use Semitexa\PlatformUi\Attribute\HandlesUiEvent;
use Semitexa\PlatformUi\Attribute\UiSlot;
use Semitexa\PlatformUi\Contract\UiEventHandlerInterface;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventResponse;
use Semitexa\Ssr\Attribute\AsComponent;
use Semitexa\Ssr\Application\Service\UiEvent\CanonicalUiMessagePublisherInterface;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;
use Semitexa\Ssr\Application\Service\UiEvent\UiComponentStateMessage;
use Semitexa\Ssr\Application\Service\UiEvent\UiEventEnvelope;
use Semitexa\Ssr\Application\Service\UiEvent\UiPatchMessage;
use Semitexa\Ssr\Application\Service\UiEvent\UiResponseDispatcherInterface;
use Semitexa\Ssr\Application\Service\UiEvent\UiResponseDispatchResult;
use Semitexa\Ssr\Application\Service\UiEvent\UiSseMessageInterface;

/**
 * End-to-end tests for the platform-ui binding of
 * {@see UiResponseDispatcherInterface}. Drives a real signed envelope
 * through the adapter → existing {@see UiInteractionDispatcher} →
 * FormComponent pipeline and asserts the canonical
 * {@see UiResponseDispatchResult} shape.
 *
 * Uses the same APP_SECRET + APP_ENV harness as
 * {@see FormSubmitDispatchTest} so the signed-context machinery and the
 * submit security policy are configured identically; this guarantees
 * the canonical /__ui/event path goes through the same hardened
 * dispatcher as /__ui/dispatch (Phase 3 ADR-0001 requirement).
 */
final class PlatformUiResponseDispatcherTest extends TestCase
{
    private const FORM_INSTANCE = 'uci_pui_response_dispatcher_test_01';

    private ?string $previousSecret = null;
    private ?string $previousEnv = null;

    protected function setUp(): void
    {
        $prevSecret = getenv('APP_SECRET');
        $this->previousSecret = $prevSecret === false ? null : $prevSecret;
        $prevEnv = getenv('APP_ENV');
        $this->previousEnv = $prevEnv === false ? null : $prevEnv;
        putenv('APP_SECRET=platform-ui-response-dispatcher-test');
        putenv('APP_ENV=dev');

        UiPrimitiveRegistry::reset();
        UiComponentRegistry::reset();
        UiPrimitiveRegistry::register(
            (new UiPrimitiveMetadataFactory())->fromClass(FormRootPrimitive::class),
        );
        UiComponentRegistry::register(
            (new UiComponentMetadataFactory())->fromClass(FormComponent::class),
        );
        // Use the lighter "signed-ctx only" submit policy so the test
        // doesn't have to mint CSRF tokens; the adapter's contract is
        // not about CSRF (that's enforced by the legacy dispatcher's
        // own pipeline, which already has dedicated coverage in
        // FormSubmitDispatchTest).
        UiFormSubmitSecurityPolicy::setActive(new SignedContextOnlyUiFormSubmitSecurityPolicy());
    }

    protected function tearDown(): void
    {
        UiPrimitiveRegistry::reset();
        UiComponentRegistry::reset();
        UiFormSubmitSecurityPolicy::reset();
        if ($this->previousSecret === null) {
            putenv('APP_SECRET');
        } else {
            putenv('APP_SECRET=' . $this->previousSecret);
        }
        if ($this->previousEnv === null) {
            putenv('APP_ENV');
        } else {
            putenv('APP_ENV=' . $this->previousEnv);
        }
    }

    #[Test]
    public function adapter_implements_canonical_ui_response_dispatcher_interface(): void
    {
        self::assertInstanceOf(
            UiResponseDispatcherInterface::class,
            $this->newAdapter(),
        );
    }

    #[Test]
    public function adapter_is_bound_via_satisfies_service_contract(): void
    {
        $reflection = new ReflectionClass(PlatformUiResponseDispatcher::class);
        $attrs = $reflection->getAttributes(SatisfiesServiceContract::class);
        self::assertCount(
            1,
            $attrs,
            'PlatformUiResponseDispatcher must carry exactly one '
            . '#[SatisfiesServiceContract] attribute.',
        );
        $args = $attrs[0]->getArguments();
        self::assertSame(
            UiResponseDispatcherInterface::class,
            $args['of'] ?? $args[0] ?? null,
            'PlatformUiResponseDispatcher must satisfy '
            . 'UiResponseDispatcherInterface.',
        );
    }

    #[Test]
    public function valid_form_submit_dispatches_through_legacy_pipeline_and_returns_accepted(): void
    {
        $envelope = $this->envelope($this->submitCtx(), [
            'form' => ['values' => [
                'access_code'         => 'abcd',
                'confirm_access_code' => 'abcd',
            ]],
        ]);

        $result = $this->newAdapter()->dispatch($envelope, $this->verifyClaims($envelope));

        self::assertSame(200, $result->statusCode);
        self::assertSame('accepted', $result->status);
        self::assertSame('dispatch', $result->phase);
        self::assertSame('accepted', $result->reason);
        // The body carries the legacy dispatcher's safe shape.
        self::assertArrayHasKey('kind', $result->body);
        self::assertArrayHasKey('patches', $result->body);
        self::assertArrayHasKey('debug', $result->body);
        self::assertSame($envelope->eventId, $result->body['dispatchId']);
        // Patches must be a list of plain arrays (UiResponsePatch::toJsonShape).
        self::assertIsArray($result->body['patches']);
        foreach ($result->body['patches'] as $patch) {
            self::assertIsArray($patch);
            self::assertArrayHasKey('op', $patch);
            self::assertArrayHasKey('target', $patch);
            self::assertArrayHasKey('instance', $patch['target']);
        }
    }

    #[Test]
    public function dispatch_id_maps_to_envelope_event_id_for_replay_protection(): void
    {
        $envelope = $this->envelope($this->submitCtx(), [
            'form' => ['values' => [
                'access_code'         => 'abcd',
                'confirm_access_code' => 'abcd',
            ]],
        ]);
        $adapter = $this->newAdapter();
        $claims  = $this->verifyClaims($envelope);

        // First dispatch claims the replay key — succeeds.
        $first = $adapter->dispatch($envelope, $claims);
        self::assertSame('accepted', $first->status);
        self::assertSame($envelope->eventId, $first->body['dispatchId']);

        // Replaying the SAME envelope (same eventId → same dispatchId) must
        // be rejected by the legacy replay store: the adapter forwards the
        // eventId as the dispatchId, so the replay key collides.
        $second = $adapter->dispatch($envelope, $claims);
        self::assertSame('rejected', $second->status);
        self::assertSame('duplicate_dispatch', $second->reason);
        self::assertSame(409, $second->statusCode);
    }

    #[Test]
    public function tampered_signed_context_is_rejected_with_safe_envelope(): void
    {
        $validCtx = $this->submitCtx();
        // Mutate one byte of the signed-ctx blob — HMAC verification fails
        // inside the legacy dispatcher, which throws
        // UiInteractionForbiddenException('invalid_signed_ctx', ...).
        $tampered = $validCtx . 'X';

        $envelope = $this->envelope($tampered, []);

        // SignedContext::verify() on the tampered blob returns null in
        // production at the framework endpoint, so the adapter would
        // never see a `null` claims array. Simulate "framework gave us
        // empty claims" by passing an empty array — the legacy
        // dispatcher's own verification (step 4) is the one we're
        // exercising.
        $result = $this->newAdapter()->dispatch($envelope, []);

        self::assertSame(403, $result->statusCode);
        self::assertSame('rejected', $result->status);
        self::assertSame('dispatch', $result->phase);
        self::assertSame('invalid_signed_ctx', $result->reason);
        self::assertNotSame('', $result->message);
    }

    #[Test]
    public function result_body_does_not_leak_signed_context_or_internals(): void
    {
        $envelope = $this->envelope($this->submitCtx(), [
            'form' => ['values' => [
                'access_code'         => 'abcd',
                'confirm_access_code' => 'abcd',
            ]],
        ]);
        $result = $this->newAdapter()->dispatch($envelope, $this->verifyClaims($envelope));

        $encoded = json_encode(
            $result->body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        self::assertIsString($encoded);
        // The raw signed-context blob must never appear in the body.
        self::assertStringNotContainsString($envelope->signedContext, $encoded);
        // None of the verified-claim secrets / framework internals leak.
        self::assertStringNotContainsString('signedContext', $encoded);
        self::assertStringNotContainsString('signed_context', $encoded);
        self::assertStringNotContainsString('csrf', $encoded);
        self::assertStringNotContainsString('APP_SECRET', $encoded);
        // No FQCNs or stack-trace markers.
        self::assertStringNotContainsString('\\Application\\', $encoded);
        self::assertStringNotContainsString('Stack trace', $encoded);
        self::assertStringNotContainsString('#0 /', $encoded);
        // No envelope-reserved keys (the endpoint composes those itself
        // and would drop them as collisions; we don't want to even try).
        self::assertArrayNotHasKey('eventId',       $result->body);
        self::assertArrayNotHasKey('correlationId', $result->body);
        self::assertArrayNotHasKey('semanticEvent', $result->body);
        self::assertArrayNotHasKey('schemaVersion', $result->body);
        self::assertArrayNotHasKey('signedContext', $result->body);
    }

    #[Test]
    public function sub_claim_routes_patches_to_canonical_publisher_and_drops_inline(): void
    {
        $publisher = new RecordingCanonicalUiMessagePublisher();
        $adapter = $this->newAdapter()->withPublisher($publisher);

        $signedCtx = $this->submitCtxWithSub('sse_session_alpha');
        $envelope = $this->envelope($signedCtx, [
            'form' => ['values' => [
                'access_code'         => 'abcd',
                'confirm_access_code' => 'abcd',
            ]],
        ]);

        $result = $adapter->dispatch($envelope, $this->verifyClaims($envelope));

        self::assertSame(200, $result->statusCode);
        self::assertSame('accepted', $result->status);
        // Inline patches must be DROPPED when the publisher took the
        // patches over the SSE channel — clients on the canonical
        // typed channel would otherwise apply them twice.
        self::assertSame([], $result->body['patches']);
        self::assertArrayHasKey('streamedPatchCount', $result->body);
        self::assertGreaterThan(0, $result->body['streamedPatchCount']);
        // Publisher received the right session id and the right
        // message type.
        self::assertSame('sse_session_alpha', $publisher->lastSessionId);
        self::assertSame(
            $result->body['streamedPatchCount'],
            count($publisher->published),
        );
        foreach ($publisher->published as $msg) {
            self::assertInstanceOf(UiPatchMessage::class, $msg);
            $payload = $msg->toSsePayload();
            self::assertSame('ui.patch', $payload['_type']);
            self::assertSame($envelope->correlationId, $payload['correlationId'] ?? null);
            // No secret/internal data leaks into the typed message.
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
            self::assertIsString($encoded);
            self::assertStringNotContainsString($envelope->signedContext, $encoded);
            self::assertStringNotContainsString('APP_SECRET', $encoded);
        }
    }

    #[Test]
    public function without_sub_claim_inline_patches_are_preserved(): void
    {
        $publisher = new RecordingCanonicalUiMessagePublisher();
        $adapter = $this->newAdapter()->withPublisher($publisher);

        // The default submitCtx() does NOT carry a `sub` claim — old
        // ctxs remain backward-compatible.
        $envelope = $this->envelope($this->submitCtx(), [
            'form' => ['values' => [
                'access_code'         => 'abcd',
                'confirm_access_code' => 'abcd',
            ]],
        ]);

        $result = $adapter->dispatch($envelope, $this->verifyClaims($envelope));

        self::assertSame('accepted', $result->status);
        self::assertNotEmpty($result->body['patches']);
        self::assertArrayNotHasKey('streamedPatchCount', $result->body);
        // Publisher was NOT invoked.
        self::assertSame([], $publisher->published);
        self::assertNull($publisher->lastSessionId);
    }

    #[Test]
    public function publisher_failure_falls_back_to_inline_patches(): void
    {
        $publisher = new ThrowingCanonicalUiMessagePublisher();
        $adapter = $this->newAdapter()->withPublisher($publisher);

        $envelope = $this->envelope($this->submitCtxWithSub('sse_session_beta'), [
            'form' => ['values' => [
                'access_code'         => 'abcd',
                'confirm_access_code' => 'abcd',
            ]],
        ]);

        $result = $adapter->dispatch($envelope, $this->verifyClaims($envelope));

        // Publisher threw → patches were NOT durably delivered via
        // SSE. The adapter falls back to inline so the dispatch
        // outcome is still observable to the client.
        self::assertSame('accepted', $result->status);
        self::assertNotEmpty($result->body['patches']);
        self::assertArrayNotHasKey('streamedPatchCount', $result->body);
    }

    #[Test]
    public function unsafe_sub_claim_shape_is_treated_as_absent(): void
    {
        $publisher = new RecordingCanonicalUiMessagePublisher();
        $adapter = $this->newAdapter()->withPublisher($publisher);

        // Forge a signed ctx with an unsafe `sub` shape directly — the
        // platform-ui manifest builder would refuse this, but the
        // dispatcher must also defend against any HMAC-valid ctx
        // minted by a non-platform component.
        $signedCtx = SignedContext::sign([
            'c' => 'platform.form',
            'i' => self::FORM_INSTANCE,
            'p' => 'form',
            'e' => 'submit',
            'cfg' => $this->fieldConfig(),
            'sub' => "evil\nsub",   // CR/LF + space — fails the safe pattern
        ]);
        $envelope = $this->envelope($signedCtx, [
            'form' => ['values' => [
                'access_code'         => 'abcd',
                'confirm_access_code' => 'abcd',
            ]],
        ]);

        $result = $adapter->dispatch($envelope, $this->verifyClaims($envelope));

        // Dispatcher treats unsafe `sub` as absent → publisher untouched,
        // inline patches returned.
        self::assertSame('accepted', $result->status);
        self::assertSame([], $publisher->published);
        self::assertNull($publisher->lastSessionId);
        self::assertNotEmpty($result->body['patches']);
    }

    #[Test]
    public function class_level_handles_ui_event_service_handler_dispatched_through_canonical_endpoint(): void
    {
        // Phase 5: PlatformUiResponseDispatcher must propagate a
        // container-backed handler resolver into UiInteractionDispatcher
        // so service handlers (#[HandlesUiEvent]) bound to slots resolve
        // identically on /__ui/event and /__ui/dispatch.
        UiComponentRegistry::register(
            (new UiComponentMetadataFactory())->fromClass(PrdServiceHandlerComponent::class),
        );
        UiComponentRegistry::registerExternalFromClass(PrdServiceHandlerHandler::class);
        $handler = new PrdServiceHandlerHandler();
        $container = new PrdServiceHandlerStubContainer([
            PrdServiceHandlerHandler::class => $handler,
        ]);
        $adapter = $this->newAdapter()->withContainer($container);

        $envelope = $this->envelope(
            $this->prdServiceHandlerCtx(),
            ['value' => ['q' => 'integration-smoke']],
        );

        $result = $adapter->dispatch($envelope, $this->verifyClaims($envelope));

        self::assertSame(200, $result->statusCode);
        self::assertSame('accepted', $result->status);
        // The handler returns UiEventResponse::patch(state: [...]); adapter
        // surfaces that state under debug.state. Confirm it flowed through.
        self::assertArrayHasKey('debug', $result->body);
        self::assertSame(['from' => 'PrdServiceHandlerHandler'], $result->body['debug']['state']);
        self::assertSame(['q' => 'integration-smoke'], $handler->capturedPayload);
    }

    #[Test]
    public function state_response_with_sub_and_instance_publishes_component_state_and_strips_http_state(): void
    {
        // Phase 6: when a handler returns a whole-state snapshot (the grid
        // row bag) AND the ctx carries both a `sub` (page-session channel)
        // and an `i` (target instance) claim AND the publisher is wired,
        // the dispatcher publishes a typed `ui.componentState` frame over
        // the canonical channel and REMOVES the state from the HTTP body.
        UiComponentRegistry::register(
            (new UiComponentMetadataFactory())->fromClass(PrdServiceHandlerComponent::class),
        );
        UiComponentRegistry::registerExternalFromClass(PrdServiceHandlerHandler::class);
        $handler = new PrdServiceHandlerHandler();
        $container = new PrdServiceHandlerStubContainer([
            PrdServiceHandlerHandler::class => $handler,
        ]);
        $publisher = new RecordingCanonicalUiMessagePublisher();
        $adapter = $this->newAdapter()->withContainer($container)->withPublisher($publisher);

        $envelope = $this->envelope(
            $this->prdServiceHandlerCtxWithSub('sse_session_grid'),
            ['value' => ['q' => 'sse-data']],
        );

        $result = $adapter->dispatch($envelope, $this->verifyClaims($envelope));

        self::assertSame(200, $result->statusCode);
        self::assertSame('accepted', $result->status);

        // HTTP body is a bare ack — the row state is gone from debug.
        self::assertArrayHasKey('debug', $result->body);
        self::assertArrayNotHasKey('state', $result->body['debug']);
        self::assertSame(1, $result->body['streamedStateCount']);

        // The frame was published to the page-session channel, addressed
        // to the component instance, carrying the envelope correlationId.
        self::assertSame('sse_session_grid', $publisher->lastSessionId);
        self::assertCount(1, $publisher->published);
        $msg = $publisher->published[0];
        self::assertInstanceOf(UiComponentStateMessage::class, $msg);
        $payload = $msg->toSsePayload();
        self::assertSame('ui.componentState', $payload['_type']);
        self::assertSame('uci_prd_service_handler_test_01', $payload['componentInstanceId']);
        self::assertSame(['from' => 'PrdServiceHandlerHandler'], $payload['state']);
        self::assertSame($envelope->correlationId, $payload['correlationId'] ?? null);

        // No secret/internal data leaks into the typed message.
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        self::assertIsString($encoded);
        self::assertStringNotContainsString($envelope->signedContext, $encoded);
        self::assertStringNotContainsString('APP_SECRET', $encoded);
    }

    #[Test]
    public function state_publish_failure_falls_back_to_inline_state(): void
    {
        // A transport-level publish failure for the state frame must not
        // escape the dispatcher: the state stays inline in `debug` so the
        // client can still render via the legacy fallback path, and no
        // `streamedStateCount` is advertised.
        UiComponentRegistry::register(
            (new UiComponentMetadataFactory())->fromClass(PrdServiceHandlerComponent::class),
        );
        UiComponentRegistry::registerExternalFromClass(PrdServiceHandlerHandler::class);
        $container = new PrdServiceHandlerStubContainer([
            PrdServiceHandlerHandler::class => new PrdServiceHandlerHandler(),
        ]);
        $publisher = new ThrowingCanonicalUiMessagePublisher();
        $adapter = $this->newAdapter()->withContainer($container)->withPublisher($publisher);

        $envelope = $this->envelope(
            $this->prdServiceHandlerCtxWithSub('sse_session_grid'),
            ['value' => ['q' => 'sse-data']],
        );

        $result = $adapter->dispatch($envelope, $this->verifyClaims($envelope));

        self::assertSame('accepted', $result->status);
        self::assertSame(['from' => 'PrdServiceHandlerHandler'], $result->body['debug']['state']);
        self::assertArrayNotHasKey('streamedStateCount', $result->body);
    }

    #[Test]
    public function service_handler_dispatch_without_container_returns_configuration_error(): void
    {
        UiComponentRegistry::register(
            (new UiComponentMetadataFactory())->fromClass(PrdServiceHandlerComponent::class),
        );
        UiComponentRegistry::registerExternalFromClass(PrdServiceHandlerHandler::class);

        // No .withContainer(...) — buildHandlerResolver() returns null;
        // UiInteractionDispatcher's service-handler branch maps that to
        // a 422 with reason `ui_handler_resolver_missing`.
        $adapter = $this->newAdapter();
        $envelope = $this->envelope($this->prdServiceHandlerCtx(), ['value' => []]);

        $result = $adapter->dispatch($envelope, $this->verifyClaims($envelope));

        // UiInteractionConfigurationException maps to 503 (service-not-ready),
        // which translateFailure routes to status='error'.
        self::assertSame(503, $result->statusCode);
        self::assertSame('error', $result->status);
        self::assertSame('ui_handler_resolver_missing', $result->reason);
    }

    #[Test]
    public function invalid_event_id_format_becomes_safe_invalid_dispatch_id_rejection(): void
    {
        // The legacy dispatcher enforces a strict dispatchId pattern
        // ([A-Za-z0-9][A-Za-z0-9_-]{4,127}). A canonical envelope whose
        // eventId doesn't satisfy this pattern must surface a clean
        // rejection — not an unhandled throwable.
        $envelope = new UiEventEnvelope(
            schemaVersion: UiEventEnvelope::SCHEMA_VERSION,
            eventId:       'a b',                 // contains space — violates pattern
            correlationId: 'corr-1',
            semanticEvent: 'form.submit',
            signedContext: $this->submitCtx(),
            timestamp:     '2026-05-19T00:00:00Z',
            payload:       [],
        );
        $result = $this->newAdapter()->dispatch($envelope, $this->verifyClaims($envelope));

        self::assertSame(400, $result->statusCode);
        self::assertSame('rejected', $result->status);
        self::assertSame('invalid_dispatch_id', $result->reason);
    }

    private function newAdapter(): PlatformUiResponseDispatcher
    {
        return (new PlatformUiResponseDispatcher())
            ->withReplayStore(new InMemoryUiReplayStore());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function envelope(string $signedCtx, array $payload): UiEventEnvelope
    {
        // Generate an eventId that matches the legacy dispatcher's strict
        // pattern (same shape the frontend runtime currently mints for
        // dispatchIds). We append a random hex tail so each call is
        // replay-safe within a single test.
        $eventId = 'ui_evt_' . bin2hex(random_bytes(12));

        return new UiEventEnvelope(
            schemaVersion: UiEventEnvelope::SCHEMA_VERSION,
            eventId:       $eventId,
            correlationId: 'corr-' . bin2hex(random_bytes(4)),
            semanticEvent: 'form.submit',
            signedContext: $signedCtx,
            timestamp:     '2026-05-19T00:00:00Z',
            payload:       $payload,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function verifyClaims(UiEventEnvelope $envelope): array
    {
        $claims = SignedContext::verify($envelope->signedContext);
        return is_array($claims) ? $claims : [];
    }

    private function submitCtx(): string
    {
        return SignedContext::sign([
            'c' => 'platform.form',
            'i' => self::FORM_INSTANCE,
            'p' => 'form',
            'e' => 'submit',
            'cfg' => $this->fieldConfig(),
        ]);
    }

    private function submitCtxWithSub(string $subscriberChannelId): string
    {
        return SignedContext::sign([
            'c' => 'platform.form',
            'i' => self::FORM_INSTANCE,
            'p' => 'form',
            'e' => 'submit',
            'cfg' => $this->fieldConfig(),
            'sub' => $subscriberChannelId,
        ]);
    }

    private function prdServiceHandlerCtx(): string
    {
        return SignedContext::sign([
            'c' => 'platform.test-prd-service-handler',
            'i' => 'uci_prd_service_handler_test_01',
            'p' => 'filters',
            'e' => 'submit',
        ]);
    }

    private function prdServiceHandlerCtxWithSub(string $subscriberChannelId): string
    {
        return SignedContext::sign([
            'c' => 'platform.test-prd-service-handler',
            'i' => 'uci_prd_service_handler_test_01',
            'p' => 'filters',
            'e' => 'submit',
            'sub' => $subscriberChannelId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldConfig(): array
    {
        return [
            'f' => [
                [
                    'n' => 'access_code',
                    'r' => [
                        ['n' => 'required'],
                        ['n' => 'minLength', 'p' => [4]],
                    ],
                    'l' => 'Access code',
                    'q' => true,
                ],
                [
                    'n' => 'confirm_access_code',
                    'r' => [
                        ['n' => 'required'],
                        ['n' => 'sameAsField', 'p' => ['access_code', 'Codes must match.']],
                    ],
                    'l' => 'Confirm access code',
                    'q' => true,
                ],
            ],
        ];
    }
}

/**
 * Records the messages a {@see PlatformUiResponseDispatcher} hands off
 * to the canonical publisher. Lets the test assert WHAT was published
 * without booting Swoole / Redis / the real KISS transport.
 */
final class RecordingCanonicalUiMessagePublisher implements CanonicalUiMessagePublisherInterface
{
    public ?string $lastSessionId = null;

    /** @var list<UiSseMessageInterface> */
    public array $published = [];

    public function publish(string $sessionId, UiSseMessageInterface $message): void
    {
        $this->lastSessionId = $sessionId;
        $this->published[] = $message;
    }

    public function publishToUser(string $userId, UiSseMessageInterface $message): int
    {
        $this->lastSessionId = $userId;
        $this->published[] = $message;
        return 1;
    }
}

/**
 * Simulates a transport-level publisher failure (Redis down, Swoole
 * table evicted, network blip). The dispatcher must fall back to
 * inline patches when this happens.
 */
final class ThrowingCanonicalUiMessagePublisher implements CanonicalUiMessagePublisherInterface
{
    public function publish(string $sessionId, UiSseMessageInterface $message): void
    {
        throw new \RuntimeException('canonical publisher test failure');
    }

    public function publishToUser(string $userId, UiSseMessageInterface $message): int
    {
        throw new \RuntimeException('canonical publisher test failure');
    }
}

// ---------------------------------------------------------------------------
// Phase 5 — service-handler resolver propagation fixtures
// ---------------------------------------------------------------------------

#[AsComponent(name: 'platform.test-prd-service-handler', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiSlot(name: 'filters', description: 'fixture binding seam for service-handler dispatch')]
final class PrdServiceHandlerComponent {}

#[HandlesUiEvent(component: PrdServiceHandlerComponent::class, part: 'filters', event: 'submit')]
final class PrdServiceHandlerHandler implements UiEventHandlerInterface
{
    /** @var array<string, mixed>|null */
    public ?array $capturedPayload = null;

    public function handle(object $payload, UiEventContext $context): UiEventResponse
    {
        $arr = (array) $payload;
        $this->capturedPayload = isset($arr['value']) && is_array($arr['value']) ? $arr['value'] : $arr;
        return UiEventResponse::patch(state: ['from' => 'PrdServiceHandlerHandler']);
    }
}

/**
 * Minimal PSR-11 container used by the resolver-propagation tests.
 * Returns configured singletons by FQCN; throws NotFoundException
 * for unknown keys.
 */
final class PrdServiceHandlerStubContainer implements ContainerInterface
{
    /** @param array<string, object> $services */
    public function __construct(private readonly array $services) {}

    public function get(string $id): object
    {
        if (!isset($this->services[$id])) {
            throw new class("Service '$id' not found") extends \RuntimeException implements NotFoundExceptionInterface {};
        }
        return $this->services[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }
}
