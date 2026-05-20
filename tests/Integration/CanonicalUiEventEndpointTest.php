<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Request;
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
use Semitexa\Ssr\Application\Handler\PayloadHandler\UiEventEndpointHandler;
use Semitexa\Ssr\Application\Payload\Request\UiEventEnvelopePayload;
use Semitexa\Ssr\Application\Service\UiEvent\CanonicalUiMessagePublisherInterface;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;
use Semitexa\Ssr\Application\Service\UiEvent\UiEventEnvelope;
use Semitexa\Ssr\Application\Service\UiEvent\UiPatchMessage;
use Semitexa\Ssr\Application\Service\UiEvent\UiSseMessageInterface;

/**
 * End-to-end test for the canonical inbound transport.
 *
 * Drives a real `UiEventEnvelope` JSON body — the exact shape
 * `event-runtime.js` now ships for the default `/__ui/event` endpoint —
 * through the framework's {@see UiEventEndpointHandler} into the
 * platform-ui {@see PlatformUiResponseDispatcher} and the legacy
 * {@see UiInteractionDispatcher}. Asserts the wire response carries
 * the canonical envelope keys and a safe success body, matching what
 * the legacy `/__ui/dispatch` path produces for an equivalent input.
 *
 * Bridges the JavaScript-side wire-shape pin (`EventRuntimeAssetTest::
 * transport_canonical_wire_body_matches_ui_event_envelope_shape`) and
 * the PHP-side adapter pin
 * (`PlatformUiResponseDispatcherTest::valid_form_submit_dispatches_…`)
 * so a future contributor who breaks either side surfaces the failure
 * end-to-end.
 */
final class CanonicalUiEventEndpointTest extends TestCase
{
    private const FORM_INSTANCE = 'uci_canonical_event_endpoint_test_01';

    private ?string $previousSecret = null;
    private ?string $previousEnv = null;

    protected function setUp(): void
    {
        $prevSecret = getenv('APP_SECRET');
        $this->previousSecret = $prevSecret === false ? null : $prevSecret;
        $prevEnv = getenv('APP_ENV');
        $this->previousEnv = $prevEnv === false ? null : $prevEnv;
        putenv('APP_SECRET=canonical-ui-event-endpoint-test');
        putenv('APP_ENV=dev');

        UiPrimitiveRegistry::reset();
        UiComponentRegistry::reset();
        UiPrimitiveRegistry::register(
            (new UiPrimitiveMetadataFactory())->fromClass(FormRootPrimitive::class),
        );
        UiComponentRegistry::register(
            (new UiComponentMetadataFactory())->fromClass(FormComponent::class),
        );
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
    public function canonical_envelope_post_returns_accepted_with_safe_body(): void
    {
        $envelope = $this->frontendEnvelope($this->submitCtx(), [
            'value' => 'abcd',
            'form' => ['values' => [
                'access_code'         => 'abcd',
                'confirm_access_code' => 'abcd',
            ]],
        ]);

        $response = $this->postEnvelope($envelope);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        // Canonical envelope keys composed by the framework endpoint.
        self::assertSame('accepted', $body['status']);
        self::assertSame('dispatch', $body['phase']);
        self::assertSame('accepted', $body['reason']);
        self::assertSame($envelope['eventId'], $body['eventId']);
        self::assertSame($envelope['correlationId'], $body['correlationId']);
        self::assertSame($envelope['semanticEvent'], $body['semanticEvent']);
        self::assertSame(UiEventEnvelope::SCHEMA_VERSION, $body['schemaVersion']);
        self::assertSame(['present' => true, 'verified' => true], $body['signedContext']);
        // Adapter body fields (folded into the envelope by the framework
        // endpoint after dropping reserved keys).
        self::assertArrayHasKey('kind', $body);
        self::assertArrayHasKey('patches', $body);
        self::assertArrayHasKey('dispatchId', $body);
        self::assertSame($envelope['eventId'], $body['dispatchId']);
        self::assertIsArray($body['patches']);
    }

    #[Test]
    public function canonical_endpoint_rejects_tampered_signed_context(): void
    {
        $envelope = $this->frontendEnvelope($this->submitCtx() . 'X', []);

        $response = $this->postEnvelope($envelope);

        // Framework-level signed-context verification fails closed BEFORE
        // the adapter is reached — the endpoint emits a ValidationException
        // at HTTP 422 with the documented signedContext error.
        self::assertSame(422, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        // No FQCN / trace / signed-ctx blob leakage in the failure path.
        $raw = $response->getContent();
        self::assertIsString($raw);
        self::assertStringNotContainsString($envelope['signedContext'], $raw);
        self::assertStringNotContainsString('Stack trace', $raw);
        self::assertStringNotContainsString('Semitexa\\\\Ssr\\\\Application', $raw);
    }

    #[Test]
    public function envelope_with_sub_claim_publishes_typed_ui_patch_and_empties_inline(): void
    {
        $publisher = new EndpointTestPublisher();
        $envelope = $this->frontendEnvelope(
            $this->submitCtxWithSub('sse_endpoint_alpha'),
            [
                'value' => 'abcd',
                'form' => ['values' => [
                    'access_code'         => 'abcd',
                    'confirm_access_code' => 'abcd',
                ]],
            ],
        );

        $response = $this->postEnvelope($envelope, $publisher);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('accepted', $body['status']);
        // Inline patches dropped — patches are on the canonical typed
        // stream instead.
        self::assertSame([], $body['patches']);
        self::assertGreaterThan(0, $body['streamedPatchCount']);
        // Publisher saw the right session id and ui.patch messages.
        self::assertSame('sse_endpoint_alpha', $publisher->lastSessionId);
        self::assertSame($body['streamedPatchCount'], count($publisher->published));
        foreach ($publisher->published as $msg) {
            self::assertInstanceOf(UiPatchMessage::class, $msg);
            $payload = $msg->toSsePayload();
            self::assertSame('ui.patch', $payload['_type']);
        }
    }

    #[Test]
    public function envelope_without_sub_claim_remains_backward_compatible(): void
    {
        $publisher = new EndpointTestPublisher();
        $envelope = $this->frontendEnvelope($this->submitCtx(), [
            'value' => 'abcd',
            'form' => ['values' => [
                'access_code'         => 'abcd',
                'confirm_access_code' => 'abcd',
            ]],
        ]);

        $response = $this->postEnvelope($envelope, $publisher);

        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('accepted', $body['status']);
        // Old ctxs (no sub claim) keep inline patches → backward compatible.
        self::assertNotEmpty($body['patches']);
        self::assertArrayNotHasKey('streamedPatchCount', $body);
        // Publisher was NOT invoked.
        self::assertSame([], $publisher->published);
        self::assertNull($publisher->lastSessionId);
    }

    #[Test]
    public function canonical_envelope_does_not_leak_secret_keys_in_response(): void
    {
        $envelope = $this->frontendEnvelope($this->submitCtx(), [
            'value' => 'abcd',
            'form' => ['values' => [
                'access_code'         => 'abcd',
                'confirm_access_code' => 'abcd',
            ]],
        ]);
        $raw = $this->postEnvelope($envelope)->getContent();
        self::assertIsString($raw);

        // The opaque signed-context blob the frontend submitted must never
        // appear in the response. The wire response carries only
        // `{present:true, verified:true}` for the signedContext slot.
        self::assertStringNotContainsString($envelope['signedContext'], $raw);
        self::assertStringNotContainsString('APP_SECRET', $raw);
        self::assertStringNotContainsString('csrf', strtolower($raw));
        self::assertStringNotContainsString('Stack trace', $raw);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function frontendEnvelope(string $signedCtx, array $payload): array
    {
        // Same generator format event-runtime.js uses for both the
        // dispatchId AND the canonical eventId (one helper, two prefixes).
        $eventId = 'ui_evt_' . bin2hex(random_bytes(16));
        $correlationId = 'ui_cor_' . bin2hex(random_bytes(16));

        return [
            'schemaVersion' => UiEventEnvelope::SCHEMA_VERSION,
            'eventId'       => $eventId,
            'correlationId' => $correlationId,
            // Stable derivation: <component>.<event> — matches the JS
            // `deriveSemanticEvent(captured)` helper.
            'semanticEvent' => 'platform.form.submit',
            'signedContext' => $signedCtx,
            'timestamp'     => gmdate('Y-m-d\TH:i:s\Z'),
            'payload'       => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function postEnvelope(array $envelope, ?CanonicalUiMessagePublisherInterface $publisher = null): ResourceResponse
    {
        $body = json_encode($envelope, JSON_THROW_ON_ERROR);

        $request = new Request(
            method:  'POST',
            uri:     '/__ui/event',
            headers: ['Content-Type' => 'application/json'],
            query:   [],
            post:    [],
            server:  [],
            cookies: [],
            content: $body,
            files:   [],
        );

        // Reset replay store so consecutive invocations within one test
        // can dispatch fresh eventIds without bleeding across cases.
        $adapter = (new PlatformUiResponseDispatcher())
            ->withReplayStore(new InMemoryUiReplayStore());
        if ($publisher !== null) {
            $adapter = $adapter->withPublisher($publisher);
        }

        $handler = (new UiEventEndpointHandler())
            ->withRequest($request)
            ->withDispatcher($adapter);

        try {
            return $handler->handle(new UiEventEnvelopePayload(), new ResourceResponse());
        } catch (\Semitexa\Core\Exception\ValidationException $e) {
            // The framework endpoint maps malformed envelopes / tampered
            // signed contexts to ValidationException. Convert to a 422
            // JSON response shape mirroring the framework's own
            // exception-mapper path, so the test can assert on body +
            // status uniformly.
            $payload = [
                'error'   => 'validation',
                'message' => 'Validation failed.',
                'context' => $e->getErrorContext(),
            ];
            return (new ResourceResponse())
                ->setStatusCode(422)
                ->setHeader('Content-Type', 'application/json; charset=utf-8')
                ->setContent(json_encode($payload, JSON_THROW_ON_ERROR));
        }
    }

    private function submitCtx(): string
    {
        return SignedContext::sign($this->ctxClaims());
    }

    private function submitCtxWithSub(string $subscriberChannelId): string
    {
        $claims = $this->ctxClaims();
        $claims['sub'] = $subscriberChannelId;
        return SignedContext::sign($claims);
    }

    /**
     * @return array<string, mixed>
     */
    private function ctxClaims(): array
    {
        return [
            'c' => 'platform.form',
            'i' => self::FORM_INSTANCE,
            'p' => 'form',
            'e' => 'submit',
            'cfg' => [
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
            ],
        ];
    }
}

/**
 * Minimal canonical-publisher recorder. Same pattern as
 * RecordingCanonicalUiMessagePublisher in PlatformUiResponseDispatcherTest,
 * inlined here so the two integration tests stay independent.
 */
final class EndpointTestPublisher implements CanonicalUiMessagePublisherInterface
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
