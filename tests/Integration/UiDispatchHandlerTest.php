<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Request;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\PlatformUi\Application\Component\Builtin\FieldComponent;
use Semitexa\PlatformUi\Application\Handler\PayloadHandler\UiDispatchHandler;
use Semitexa\PlatformUi\Application\Payload\Request\UiDispatchPayload;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Semitexa\PlatformUi\Application\Service\Event\UiInteractionAuthorizerInterface;
use Semitexa\PlatformUi\Domain\Model\Component\UiComponentMetadata;
use Semitexa\PlatformUi\Domain\Model\Component\UiOnMetadata;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionEvent;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;

/**
 * End-to-end handler tests for POST /__ui/dispatch.
 *
 * Constructs the handler directly with a stub Request so the test
 * exercises the same decode → guard → dispatch → JSON-encode pipeline
 * that runs in production. No cache manager is injected, so each test
 * runs against a fresh worker-local InMemoryUiReplayStore.
 */
final class UiDispatchHandlerTest extends TestCase
{
    private ?string $previousSecret = null;
    private ?string $previousEnv = null;
    private int $dispatchSeq = 0;

    private function freshDispatchId(): string
    {
        $this->dispatchSeq++;
        return sprintf('ui_evt_%032s', dechex(($this->dispatchSeq << 16) | random_int(0, 0xFFFF)));
    }

    protected function setUp(): void
    {
        $prev = getenv('APP_SECRET');
        $this->previousSecret = $prev === false ? null : $prev;
        $prevEnv = getenv('APP_ENV');
        $this->previousEnv = $prevEnv === false ? null : $prevEnv;
        putenv('APP_SECRET=platform-ui-handler-test');
        putenv('APP_ENV=dev');

        UiComponentRegistry::reset();
        UiComponentRegistry::register(
            (new UiComponentMetadataFactory())->fromClass(FieldComponent::class),
        );
        $this->dispatchSeq = 0;
    }

    protected function tearDown(): void
    {
        UiComponentRegistry::reset();
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

    private function freshCtx(?string $updates = 'value', ?string $component = 'platform.field', ?string $event = 'change'): string
    {
        return SignedContext::sign([
            'c' => $component,
            'i' => 'uci_handler_test_01',
            'p' => 'input',
            'e' => $event,
            'u' => $updates ?? 'value',
        ]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function buildBody(string $ctx, ?string $dispatchId = null, array $extra = []): string
    {
        $body = ['ctx' => $ctx];
        if ($dispatchId !== null) {
            $body['dispatchId'] = $dispatchId;
        }
        foreach ($extra as $k => $v) {
            $body[$k] = $v;
        }
        return json_encode($body, JSON_THROW_ON_ERROR);
    }

    private function invokeHandler(
        string $body,
        ?UiInteractionAuthorizerInterface $authorizer = null,
        ?UiDispatchHandler $handler = null,
    ): ResourceResponse {
        $request = $this->makeRequest($body);
        $handler ??= new UiDispatchHandler();
        $handler = $handler->withRequest($request);
        if ($authorizer !== null) {
            $handler = $handler->withAuthorizer($authorizer);
        }
        $resource = new ResourceResponse();
        return $handler->handle(new UiDispatchPayload(), $resource);
    }

    private function makeRequest(string $body): Request
    {
        return new Request(
            method: 'POST',
            uri: '/__ui/dispatch',
            headers: [],
            query: [],
            post: [],
            server: [],
            cookies: [],
            content: $body,
            files: [],
        );
    }

    /** @return array<string, mixed> */
    private function decodeJson(ResourceResponse $response): array
    {
        $decoded = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }

    #[Test]
    public function valid_request_returns_200_ack_with_safe_body(): void
    {
        $dispatchId = $this->freshDispatchId();
        $body = $this->buildBody(
            $this->freshCtx(),
            $dispatchId,
            ['payload' => ['value' => 'taras@example.com']],
        );

        $response = $this->invokeHandler($body);
        self::assertSame(200, $response->getStatusCode());

        $data = $this->decodeJson($response);
        self::assertTrue($data['ok']);
        self::assertTrue($data['handled']);
        self::assertSame('patch', $data['kind']);
        self::assertSame($dispatchId, $data['dispatchId']);
        self::assertSame('platform.field', $data['component']);
        self::assertSame('uci_handler_test_01', $data['instance']);
        self::assertSame('input', $data['part']);
        self::assertSame('change', $data['event']);
        self::assertSame('value', $data['updates']);
        self::assertSame('taras@example.com', $data['debug']['value']);

        self::assertIsArray($data['patches']);
        // FieldComponent::onInputChanged() emits 4 patches: 3 validation
        // (aria-invalid, ui-state, validation-message setText) + 1
        // server-ack setText (preserved for the dispatch-demo).
        self::assertCount(4, $data['patches']);
        // Validation patches: aria-invalid removed (null) + ui-state=valid.
        self::assertSame('setAttribute', $data['patches'][0]['op']);
        self::assertSame('aria-invalid', $data['patches'][0]['attribute']);
        self::assertNull($data['patches'][0]['value']);
        self::assertSame(['instance' => 'uci_handler_test_01', 'part' => 'input'], $data['patches'][0]['target']);
        self::assertSame('setAttribute', $data['patches'][1]['op']);
        self::assertSame('ui-state', $data['patches'][1]['attribute']);
        self::assertSame('valid', $data['patches'][1]['value']);
        // Validation message + server-ack echo.
        self::assertSame('setText', $data['patches'][2]['op']);
        self::assertSame(['instance' => 'uci_handler_test_01', 'name' => 'validation-message'], $data['patches'][2]['target']);
        self::assertSame('Looks good.', $data['patches'][2]['value']);
        self::assertSame('setText', $data['patches'][3]['op']);
        self::assertSame(['instance' => 'uci_handler_test_01', 'name' => 'server-ack'], $data['patches'][3]['target']);
        self::assertSame('Server received: taras@example.com', $data['patches'][3]['value']);

        $raw = $response->getContent();
        self::assertStringNotContainsString('onInputChanged', $raw);
        self::assertStringNotContainsString('FieldComponent', $raw);
        self::assertStringNotContainsString('Semitexa\\\\PlatformUi', $raw);
    }

    #[Test]
    public function invalid_value_returns_validation_patches_with_invalid_aria_and_state(): void
    {
        $body = $this->buildBody(
            $this->freshCtx(),
            $this->freshDispatchId(),
            ['payload' => ['value' => '']],
        );
        $response = $this->invokeHandler($body);
        self::assertSame(200, $response->getStatusCode());
        $data = $this->decodeJson($response);
        self::assertSame('patch', $data['kind']);
        // 3 validation patches + 1 server-ack.
        self::assertCount(4, $data['patches']);
        self::assertSame('aria-invalid', $data['patches'][0]['attribute']);
        self::assertSame('true', $data['patches'][0]['value']);
        self::assertSame('ui-state', $data['patches'][1]['attribute']);
        self::assertSame('invalid', $data['patches'][1]['value']);
        self::assertSame('validation-message', $data['patches'][2]['target']['name']);
        self::assertSame('This field is required.', $data['patches'][2]['value']);
        // Debug surface carries the diagnostic for log correlation.
        self::assertSame('invalid', $data['debug']['validation']['state']);
        self::assertSame('This field is required.', $data['debug']['validation']['message']);
    }

    #[Test]
    public function short_value_returns_too_short_validation_patches(): void
    {
        $body = $this->buildBody(
            $this->freshCtx(),
            $this->freshDispatchId(),
            ['payload' => ['value' => 'ab']],
        );
        $response = $this->invokeHandler($body);
        $data = $this->decodeJson($response);
        self::assertSame('Please enter at least 3 characters.', $data['patches'][2]['value']);
        self::assertSame('invalid', $data['patches'][1]['value']);
    }

    #[Test]
    public function empty_body_returns_400(): void
    {
        $response = $this->invokeHandler('');
        self::assertSame(400, $response->getStatusCode());
        $data = $this->decodeJson($response);
        self::assertFalse($data['ok']);
        self::assertSame('empty_body', $data['reason']);
    }

    #[Test]
    public function malformed_json_returns_400(): void
    {
        $response = $this->invokeHandler('{not json');
        self::assertSame(400, $response->getStatusCode());
        $data = $this->decodeJson($response);
        self::assertSame('malformed_json', $data['reason']);
    }

    #[Test]
    public function body_as_list_returns_400(): void
    {
        $response = $this->invokeHandler('[]');
        self::assertSame(400, $response->getStatusCode());
        $data = $this->decodeJson($response);
        self::assertSame('body_not_object', $data['reason']);
    }

    #[Test]
    public function missing_ctx_returns_400(): void
    {
        $body = json_encode([
            'dispatchId' => $this->freshDispatchId(),
            'payload' => ['value' => 'x'],
        ], JSON_THROW_ON_ERROR);
        $response = $this->invokeHandler($body);
        self::assertSame(400, $response->getStatusCode());
        $data = $this->decodeJson($response);
        self::assertSame('missing_ctx', $data['reason']);
    }

    #[Test]
    public function missing_dispatch_id_returns_400(): void
    {
        // No dispatchId field at all.
        $body = json_encode([
            'ctx' => $this->freshCtx(),
            'payload' => ['value' => 'x'],
        ], JSON_THROW_ON_ERROR);
        $response = $this->invokeHandler($body);
        self::assertSame(400, $response->getStatusCode());
        $data = $this->decodeJson($response);
        self::assertSame('missing_dispatch_id', $data['reason']);
    }

    #[Test]
    public function empty_dispatch_id_returns_400(): void
    {
        $body = json_encode([
            'ctx' => $this->freshCtx(),
            'dispatchId' => '',
            'payload' => ['value' => 'x'],
        ], JSON_THROW_ON_ERROR);
        $response = $this->invokeHandler($body);
        self::assertSame(400, $response->getStatusCode());
        $data = $this->decodeJson($response);
        self::assertSame('missing_dispatch_id', $data['reason']);
    }

    #[Test]
    public function malformed_dispatch_id_returns_400_with_dispatch_id_echoed(): void
    {
        $badId = 'abc'; // too short
        $body = json_encode([
            'ctx' => $this->freshCtx(),
            'dispatchId' => $badId,
            'payload' => ['value' => 'x'],
        ], JSON_THROW_ON_ERROR);
        $response = $this->invokeHandler($body);
        self::assertSame(400, $response->getStatusCode());
        $data = $this->decodeJson($response);
        self::assertSame('invalid_dispatch_id', $data['reason']);
        self::assertSame($badId, $data['dispatchId']);
    }

    #[Test]
    public function tampered_ctx_returns_403_with_dispatch_id_echoed(): void
    {
        $ctx = $this->freshCtx();
        $tampered = substr($ctx, 0, -2) . 'AA';
        $dispatchId = $this->freshDispatchId();
        $body = $this->buildBody($tampered, $dispatchId, ['payload' => ['value' => 'x']]);
        $response = $this->invokeHandler($body);
        self::assertSame(403, $response->getStatusCode());
        $data = $this->decodeJson($response);
        self::assertSame('invalid_signed_ctx', $data['reason']);
        self::assertSame($dispatchId, $data['dispatchId']);
    }

    #[Test]
    public function forbidden_handler_field_returns_400(): void
    {
        $body = $this->buildBody(
            $this->freshCtx(),
            $this->freshDispatchId(),
            ['payload' => ['value' => 'x', 'handler' => 'evil']],
        );
        $response = $this->invokeHandler($body);
        self::assertSame(400, $response->getStatusCode());
        $data = $this->decodeJson($response);
        self::assertSame('forbidden_payload_field', $data['reason']);
        self::assertStringContainsString('payload.handler', $data['message']);
    }

    #[Test]
    public function forbidden_nested_method_field_returns_400(): void
    {
        $body = $this->buildBody(
            $this->freshCtx(),
            $this->freshDispatchId(),
            ['payload' => ['value' => 'x', 'meta' => ['method' => 'pwn()']]],
        );
        $response = $this->invokeHandler($body);
        self::assertSame(400, $response->getStatusCode());
        $data = $this->decodeJson($response);
        self::assertSame('forbidden_payload_field', $data['reason']);
        self::assertStringContainsString('payload.meta.method', $data['message']);
    }

    #[Test]
    public function unknown_component_returns_404(): void
    {
        $body = $this->buildBody(
            $this->freshCtx(component: 'platform.nope'),
            $this->freshDispatchId(),
            ['payload' => ['value' => 'x']],
        );
        $response = $this->invokeHandler($body);
        self::assertSame(404, $response->getStatusCode());
        $data = $this->decodeJson($response);
        self::assertSame('unknown_component', $data['reason']);
    }

    #[Test]
    public function updates_path_mismatch_returns_403(): void
    {
        $body = $this->buildBody(
            $this->freshCtx(updates: 'other.path'),
            $this->freshDispatchId(),
            ['payload' => ['value' => 'x']],
        );
        $response = $this->invokeHandler($body);
        self::assertSame(403, $response->getStatusCode());
        $data = $this->decodeJson($response);
        self::assertSame('updates_path_mismatch', $data['reason']);
    }

    #[Test]
    public function payload_omitted_defaults_to_empty(): void
    {
        $body = $this->buildBody($this->freshCtx(), $this->freshDispatchId());
        $response = $this->invokeHandler($body);
        self::assertSame(200, $response->getStatusCode());
        $data = $this->decodeJson($response);
        self::assertTrue($data['ok']);
        self::assertNull($data['debug']['value']);
    }

    #[Test]
    public function payload_as_list_returns_400(): void
    {
        $body = $this->buildBody(
            $this->freshCtx(),
            $this->freshDispatchId(),
            ['payload' => ['x', 'y']],
        );
        $response = $this->invokeHandler($body);
        self::assertSame(400, $response->getStatusCode());
        $data = $this->decodeJson($response);
        self::assertSame('payload_not_object', $data['reason']);
    }

    #[Test]
    public function same_ctx_same_dispatch_id_returns_409_on_second_call(): void
    {
        // Reuse one handler so its replay store survives both calls.
        $handler = new UiDispatchHandler();
        $ctx = $this->freshCtx();
        $id = $this->freshDispatchId();

        $body = $this->buildBody($ctx, $id, ['payload' => ['value' => 'first']]);
        $first = $this->invokeHandler($body, handler: $handler);
        self::assertSame(200, $first->getStatusCode());

        $second = $this->invokeHandler($body, handler: $handler);
        self::assertSame(409, $second->getStatusCode());
        $data = $this->decodeJson($second);
        self::assertSame('duplicate_dispatch', $data['reason']);
        self::assertSame($id, $data['dispatchId']);
    }

    #[Test]
    public function same_ctx_different_dispatch_id_both_succeed(): void
    {
        $handler = new UiDispatchHandler();
        $ctx = $this->freshCtx();

        $body1 = $this->buildBody($ctx, $this->freshDispatchId(), ['payload' => ['value' => 'one']]);
        $body2 = $this->buildBody($ctx, $this->freshDispatchId(), ['payload' => ['value' => 'two']]);

        $r1 = $this->invokeHandler($body1, handler: $handler);
        $r2 = $this->invokeHandler($body2, handler: $handler);

        self::assertSame(200, $r1->getStatusCode());
        self::assertSame(200, $r2->getStatusCode());
        self::assertSame('one', $this->decodeJson($r1)['debug']['value']);
        self::assertSame('two', $this->decodeJson($r2)['debug']['value']);
    }

    #[Test]
    public function production_env_with_non_shared_replay_store_returns_503_safe(): void
    {
        // Flip APP_ENV for one test only — the dispatcher reads
        // APP_ENV at construction time, and the handler builds a fresh
        // dispatcher per request.
        $prevEnv = getenv('APP_ENV');
        putenv('APP_ENV=prod');
        try {
            $body = $this->buildBody(
                $this->freshCtx(),
                $this->freshDispatchId(),
                ['payload' => ['value' => 'x']],
            );
            $response = $this->invokeHandler($body);
            self::assertSame(503, $response->getStatusCode());
            $data = $this->decodeJson($response);
            self::assertFalse($data['ok']);
            self::assertSame('ui_replay_store_not_shared', $data['reason']);
            self::assertSame(
                'UI dispatch replay protection requires a shared cache backend.',
                $data['message'],
            );
            // Safety: no FQCN / no class / no env leak.
            $raw = $response->getContent();
            self::assertStringNotContainsString('InMemoryUiReplayStore', $raw);
            self::assertStringNotContainsString('Semitexa\\\\PlatformUi', $raw);
        } finally {
            $prevEnv === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $prevEnv);
        }
    }

    #[Test]
    public function authorizer_denial_returns_403_with_dispatch_id_echoed(): void
    {
        $deny = new class implements UiInteractionAuthorizerInterface {
            public function authorize(UiInteractionEvent $event, UiComponentMetadata $component, UiOnMetadata $eventMeta): bool
            {
                return false;
            }
            public function authorizeExternal(UiInteractionEvent $event, UiComponentMetadata $component, \Semitexa\PlatformUi\Domain\Model\Component\UiExternalHandlerMetadata $externalMeta): bool
            {
                return false;
            }
        };

        $dispatchId = $this->freshDispatchId();
        $body = $this->buildBody($this->freshCtx(), $dispatchId, ['payload' => ['value' => 'x']]);
        $response = $this->invokeHandler($body, authorizer: $deny);
        self::assertSame(403, $response->getStatusCode());
        $data = $this->decodeJson($response);
        self::assertSame('interaction_forbidden', $data['reason']);
        self::assertSame($dispatchId, $data['dispatchId']);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function patchSmugglingKeys(): iterable
    {
        yield 'patches'    => ['patches'];
        yield 'patch'      => ['patch'];
        yield 'target'     => ['target'];
        yield 'selector'   => ['selector'];
        yield 'html'       => ['html'];
        yield 'script'     => ['script'];
        yield 'dispatchId' => ['dispatchId'];
        yield 'requestId'  => ['requestId'];
        yield 'eventId'    => ['eventId'];
        // Validation rule specs are SIGNED into the ctx — payload
        // cannot carry them. Smuggling attempts are rejected.
        yield 'rules'      => ['rules'];
        yield 'r'          => ['r'];
        yield 'cfg'        => ['cfg'];
        yield 'config'     => ['config'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('patchSmugglingKeys')]
    #[Test]
    public function payload_with_smuggling_key_returns_400(string $key): void
    {
        $body = $this->buildBody(
            $this->freshCtx(),
            $this->freshDispatchId(),
            ['payload' => ['value' => 'x', $key => 'evil']],
        );
        $response = $this->invokeHandler($body);
        self::assertSame(400, $response->getStatusCode());
        $data = $this->decodeJson($response);
        self::assertSame('forbidden_payload_field', $data['reason']);
        self::assertStringContainsString('payload.' . $key, $data['message']);
    }

    #[Test]
    public function nested_patch_smuggling_in_meta_is_rejected(): void
    {
        $body = $this->buildBody(
            $this->freshCtx(),
            $this->freshDispatchId(),
            [
                'payload' => [
                    'value' => 'x',
                    'meta' => [
                        'extras' => ['html' => '<script>alert(1)</script>'],
                    ],
                ],
            ],
        );
        $response = $this->invokeHandler($body);
        self::assertSame(400, $response->getStatusCode());
        $data = $this->decodeJson($response);
        self::assertSame('forbidden_payload_field', $data['reason']);
        self::assertStringContainsString('payload.meta.extras.html', $data['message']);
    }
}
