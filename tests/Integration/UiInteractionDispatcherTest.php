<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Component\Builtin\FieldComponent;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Semitexa\PlatformUi\Application\Service\Event\AllowAllUiInteractionAuthorizer;
use Semitexa\PlatformUi\Application\Service\Event\InMemoryUiReplayStore;
use Semitexa\PlatformUi\Application\Service\Event\UiEventManifestBuilder;
use Semitexa\PlatformUi\Application\Service\Event\UiInteractionAuthorizerInterface;
use Semitexa\PlatformUi\Application\Service\Event\UiInteractionDispatcher;
use Semitexa\PlatformUi\Application\Service\Event\UiPatchValidator;
use Semitexa\PlatformUi\Application\Service\Event\UiPayloadFieldGuard;
use Semitexa\PlatformUi\Application\Service\Event\UiReplayStoreInterface;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\InputPrimitive;
use Semitexa\PlatformUi\Attribute\UiOn;
use Semitexa\PlatformUi\Attribute\UiPart;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionBadRequestException;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionConfigurationException;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionConflictException;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionForbiddenException;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionNotFoundException;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionUnprocessableException;
use Semitexa\PlatformUi\Domain\Model\Component\UiComponentMetadata;
use Semitexa\PlatformUi\Domain\Model\Component\UiOnMetadata;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionEvent;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionResult;
use Semitexa\PlatformUi\Domain\Model\Event\UiResponsePatch;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;
use Semitexa\Ssr\Attribute\AsComponent;

final class UiInteractionDispatcherTest extends TestCase
{
    private ?string $previousSecret = null;
    private ?string $previousEnv = null;
    private int $dispatchSeq = 0;

    protected function setUp(): void
    {
        $prev = getenv('APP_SECRET');
        $this->previousSecret = $prev === false ? null : $prev;
        $prevEnv = getenv('APP_ENV');
        $this->previousEnv = $prevEnv === false ? null : $prevEnv;
        putenv('APP_SECRET=platform-ui-dispatcher-test');
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

    private function freshCtx(string $instance = 'uci_test_0000000001', ?string $component = null, ?string $part = null, ?string $event = null, ?string $updates = null): string
    {
        $claims = [
            'c' => $component ?? 'platform.field',
            'i' => $instance,
            'p' => $part ?? 'input',
            'e' => $event ?? 'change',
        ];
        if ($updates !== null) {
            $claims['u'] = $updates;
        } else {
            $claims['u'] = 'value';
        }
        return SignedContext::sign($claims);
    }

    /** Fresh, well-formed dispatchId for each call within a test. */
    private function freshDispatchId(): string
    {
        $this->dispatchSeq++;
        return sprintf('ui_evt_%032s', dechex(($this->dispatchSeq << 16) | random_int(0, 0xFFFF)));
    }

    private function newDispatcher(
        ?UiInteractionAuthorizerInterface $authorizer = null,
        ?UiReplayStoreInterface $replayStore = null,
        ?bool $productionLike = false,
    ): UiInteractionDispatcher {
        return new UiInteractionDispatcher(
            payloadGuard: new UiPayloadFieldGuard(),
            patchValidator: new UiPatchValidator(),
            replayStore: $replayStore ?? new InMemoryUiReplayStore(),
            authorizer: $authorizer ?? new AllowAllUiInteractionAuthorizer(),
            productionLike: $productionLike,
        );
    }

    #[Test]
    public function valid_ctx_dispatches_to_field_handler_and_returns_patch_result(): void
    {
        $ctx = $this->freshCtx();
        $result = $this->newDispatcher()->dispatch($ctx, $this->freshDispatchId(), ['value' => 'hello@example.com']);

        self::assertSame(UiInteractionResult::KIND_PATCH, $result->kind);
        self::assertSame('hello@example.com', $result->debug['value']);
        self::assertSame('uci_test_0000000001', $result->debug['instance']);
        // FieldComponent::onInputChanged() now emits a 4-patch batch:
        //   1. setAttribute aria-invalid on the input part (null → removed for valid)
        //   2. setAttribute ui-state on the input part
        //   3. setText on the validation-message name target
        //   4. setText on the server-ack name target (preserved for the dispatch-demo)
        self::assertCount(4, $result->patches);
        foreach ($result->patches as $patch) {
            self::assertSame('uci_test_0000000001', $patch->targetInstance);
        }
        // Patch 3 is the validation message; patch 4 is the server-ack echo.
        self::assertSame('setText', $result->patches[2]->op);
        self::assertSame('validation-message', $result->patches[2]->targetName);
        self::assertSame('Looks good.', $result->patches[2]->value);
        self::assertSame('setText', $result->patches[3]->op);
        self::assertSame('server-ack', $result->patches[3]->targetName);
        self::assertSame('Server received: hello@example.com', $result->patches[3]->value);
    }

    #[Test]
    public function missing_ctx_throws_400(): void
    {
        $this->expectException(UiInteractionBadRequestException::class);
        try {
            $this->newDispatcher()->dispatch('', $this->freshDispatchId(), []);
        } catch (UiInteractionBadRequestException $e) {
            self::assertSame(400, $e->httpStatus);
            self::assertSame('missing_ctx', $e->reason);
            throw $e;
        }
    }

    #[Test]
    public function missing_dispatch_id_throws_400(): void
    {
        $this->expectException(UiInteractionBadRequestException::class);
        try {
            $this->newDispatcher()->dispatch($this->freshCtx(), '', []);
        } catch (UiInteractionBadRequestException $e) {
            self::assertSame(400, $e->httpStatus);
            self::assertSame('missing_dispatch_id', $e->reason);
            throw $e;
        }
    }

    /** @return iterable<string, array{0: string}> */
    public static function malformedDispatchIds(): iterable
    {
        yield 'too short'      => ['abc'];
        yield 'leading dash'   => ['-ui_evt_0000000000'];
        yield 'leading hash'   => ['#ui_evt_0000000000'];
        yield 'with space'     => ['ui evt 0000000000'];
        yield 'with newline'   => ["ui_evt_00\n"];
        yield 'too long (129)' => [str_repeat('a', 129)];
        yield 'unicode'        => ['ui_evt_dżem_0000'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedDispatchIds')]
    #[Test]
    public function malformed_dispatch_id_throws_400(string $badId): void
    {
        $this->expectException(UiInteractionBadRequestException::class);
        try {
            $this->newDispatcher()->dispatch($this->freshCtx(), $badId, ['value' => 'x']);
        } catch (UiInteractionBadRequestException $e) {
            self::assertSame('invalid_dispatch_id', $e->reason);
            throw $e;
        }
    }

    #[Test]
    public function tampered_ctx_throws_403(): void
    {
        $ctx = $this->freshCtx();
        $tampered = substr($ctx, 0, -2) . 'AA';
        $this->expectException(UiInteractionForbiddenException::class);
        try {
            $this->newDispatcher()->dispatch($tampered, $this->freshDispatchId(), ['value' => 'x']);
        } catch (UiInteractionForbiddenException $e) {
            self::assertSame(403, $e->httpStatus);
            self::assertSame('invalid_signed_ctx', $e->reason);
            throw $e;
        }
    }

    #[Test]
    public function expired_ctx_throws_403(): void
    {
        $ctx = SignedContext::sign(
            ['c' => 'platform.field', 'i' => 'uci_exp_0000', 'p' => 'input', 'e' => 'change', 'u' => 'value'],
            1,
        );
        sleep(2);

        $this->expectException(UiInteractionForbiddenException::class);
        $this->newDispatcher()->dispatch($ctx, $this->freshDispatchId(), []);
    }

    #[Test]
    public function unknown_component_throws_404(): void
    {
        $ctx = $this->freshCtx(component: 'platform.nope');
        $this->expectException(UiInteractionNotFoundException::class);
        try {
            $this->newDispatcher()->dispatch($ctx, $this->freshDispatchId(), []);
        } catch (UiInteractionNotFoundException $e) {
            self::assertSame(404, $e->httpStatus);
            self::assertSame('unknown_component', $e->reason);
            throw $e;
        }
    }

    #[Test]
    public function unknown_part_throws_404(): void
    {
        $ctx = $this->freshCtx(part: 'ghost');
        $this->expectException(UiInteractionNotFoundException::class);
        try {
            $this->newDispatcher()->dispatch($ctx, $this->freshDispatchId(), []);
        } catch (UiInteractionNotFoundException $e) {
            self::assertSame('unknown_part', $e->reason);
            throw $e;
        }
    }

    #[Test]
    public function unknown_event_throws_404(): void
    {
        $ctx = $this->freshCtx(event: 'never-declared');
        $this->expectException(UiInteractionNotFoundException::class);
        try {
            $this->newDispatcher()->dispatch($ctx, $this->freshDispatchId(), []);
        } catch (UiInteractionNotFoundException $e) {
            self::assertSame('unknown_event', $e->reason);
            throw $e;
        }
    }

    #[Test]
    public function updates_path_mismatch_throws_403(): void
    {
        $ctx = $this->freshCtx(updates: 'something.else');
        $this->expectException(UiInteractionForbiddenException::class);
        try {
            $this->newDispatcher()->dispatch($ctx, $this->freshDispatchId(), []);
        } catch (UiInteractionForbiddenException $e) {
            self::assertSame('updates_path_mismatch', $e->reason);
            throw $e;
        }
    }

    #[Test]
    public function forbidden_payload_field_throws_400_before_signature_check(): void
    {
        $this->expectException(UiInteractionBadRequestException::class);
        try {
            $this->newDispatcher()->dispatch(
                'sc1.aaa.bbb',
                $this->freshDispatchId(),
                ['value' => 'x', 'handler' => 'evil'],
            );
        } catch (UiInteractionBadRequestException $e) {
            self::assertSame('forbidden_payload_field', $e->reason);
            throw $e;
        }
    }

    #[Test]
    public function nested_forbidden_field_in_payload_throws_400(): void
    {
        $this->expectException(UiInteractionBadRequestException::class);
        $this->expectExceptionMessageMatches('/payload\.meta\.method/');
        $this->newDispatcher()->dispatch(
            $this->freshCtx(),
            $this->freshDispatchId(),
            ['value' => 'x', 'meta' => ['method' => 'evil()']],
        );
    }

    #[Test]
    public function client_supplied_component_field_is_rejected(): void
    {
        $this->expectException(UiInteractionBadRequestException::class);
        $this->newDispatcher()->dispatch(
            $this->freshCtx(),
            $this->freshDispatchId(),
            ['component' => 'platform.evil', 'value' => 'x'],
        );
    }

    /** @return iterable<string, array{0: string}> */
    public static function dispatchIdSmugglingKeys(): iterable
    {
        yield 'dispatchId in payload' => ['dispatchId'];
        yield 'requestId in payload'  => ['requestId'];
        yield 'eventId in payload'    => ['eventId'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dispatchIdSmugglingKeys')]
    #[Test]
    public function dispatch_id_aliases_in_payload_are_rejected(string $key): void
    {
        $this->expectException(UiInteractionBadRequestException::class);
        try {
            $this->newDispatcher()->dispatch(
                $this->freshCtx(),
                $this->freshDispatchId(),
                ['value' => 'x', $key => 'evil'],
            );
        } catch (UiInteractionBadRequestException $e) {
            self::assertSame('forbidden_payload_field', $e->reason);
            self::assertStringContainsString('payload.' . $key, $e->getMessage());
            throw $e;
        }
    }

    #[Test]
    public function same_ctx_with_same_dispatch_id_is_409_duplicate(): void
    {
        $ctx = $this->freshCtx();
        $id = $this->freshDispatchId();
        $dispatcher = $this->newDispatcher();

        // First call succeeds.
        $dispatcher->dispatch($ctx, $id, ['value' => 'first']);

        // Replay: same store, same key → 409.
        $this->expectException(UiInteractionConflictException::class);
        try {
            $dispatcher->dispatch($ctx, $id, ['value' => 'second']);
        } catch (UiInteractionConflictException $e) {
            self::assertSame(409, $e->httpStatus);
            self::assertSame('duplicate_dispatch', $e->reason);
            throw $e;
        }
    }

    #[Test]
    public function same_ctx_with_different_dispatch_id_is_allowed(): void
    {
        $ctx = $this->freshCtx();
        $dispatcher = $this->newDispatcher();

        $first = $dispatcher->dispatch($ctx, $this->freshDispatchId(), ['value' => 'one']);
        $second = $dispatcher->dispatch($ctx, $this->freshDispatchId(), ['value' => 'two']);

        // Both must succeed — the signed ctx is NOT single-use, only the
        // (ctx, dispatchId) pair is.
        self::assertSame('one', $first->debug['value']);
        self::assertSame('two', $second->debug['value']);
    }

    #[Test]
    public function authorizer_denial_returns_403_without_invoking_handler(): void
    {
        $deny = new class implements UiInteractionAuthorizerInterface {
            public int $calls = 0;
            public function authorize(UiInteractionEvent $event, UiComponentMetadata $component, UiOnMetadata $eventMeta): bool
            {
                $this->calls++;
                return false;
            }
            public function authorizeExternal(UiInteractionEvent $event, UiComponentMetadata $component, \Semitexa\PlatformUi\Domain\Model\Component\UiExternalHandlerMetadata $externalMeta): bool
            {
                return false;
            }
        };

        $dispatcher = $this->newDispatcher($deny);
        $this->expectException(UiInteractionForbiddenException::class);
        try {
            $dispatcher->dispatch($this->freshCtx(), $this->freshDispatchId(), ['value' => 'should-not-reach-handler']);
        } catch (UiInteractionForbiddenException $e) {
            self::assertSame(403, $e->httpStatus);
            self::assertSame('interaction_forbidden', $e->reason);
            self::assertSame(1, $deny->calls);
            throw $e;
        }
    }

    #[Test]
    public function authorizer_runs_after_replay_claim_so_denied_id_cannot_be_retried(): void
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

        $dispatcher = $this->newDispatcher($deny);
        $ctx = $this->freshCtx();
        $id = $this->freshDispatchId();

        // First call: 403 from authz.
        try {
            $dispatcher->dispatch($ctx, $id, ['value' => 'x']);
            self::fail('Expected forbidden');
        } catch (UiInteractionForbiddenException $e) {
            self::assertSame('interaction_forbidden', $e->reason);
        }

        // Retry with the SAME dispatchId: replay store now owns it,
        // so we get 409 — even though authz would have denied again.
        $this->expectException(UiInteractionConflictException::class);
        $dispatcher->dispatch($ctx, $id, ['value' => 'x']);
    }

    #[Test]
    public function authorizer_receives_signed_identity_not_payload_identity(): void
    {
        $captured = new class implements UiInteractionAuthorizerInterface {
            public ?UiInteractionEvent $event = null;
            public function authorize(UiInteractionEvent $event, UiComponentMetadata $component, UiOnMetadata $eventMeta): bool
            {
                $this->event = $event;
                return true;
            }
            public function authorizeExternal(UiInteractionEvent $event, UiComponentMetadata $component, \Semitexa\PlatformUi\Domain\Model\Component\UiExternalHandlerMetadata $externalMeta): bool
            {
                return true;
            }
        };

        $dispatcher = $this->newDispatcher($captured);
        $dispatchId = $this->freshDispatchId();
        $dispatcher->dispatch(
            $this->freshCtx(instance: 'uci_authz_test_01'),
            $dispatchId,
            ['value' => 'whatever'],
        );

        self::assertNotNull($captured->event);
        self::assertSame('platform.field', $captured->event->componentName);
        self::assertSame('uci_authz_test_01', $captured->event->instanceId);
        self::assertSame('input', $captured->event->partName);
        self::assertSame('change', $captured->event->eventName);
        self::assertSame($dispatchId, $captured->event->dispatchId);
    }

    #[Test]
    public function manifest_built_blob_round_trips_through_dispatcher(): void
    {
        $metadata = UiComponentRegistry::get('platform.field');
        $manifest = (new UiEventManifestBuilder())->build($metadata, 'uci_round_trip_99');
        $ctx = $manifest->entries[0]->signedContext;

        $result = $this->newDispatcher()->dispatch($ctx, $this->freshDispatchId(), ['value' => 'roundtrip-ok']);

        self::assertSame(UiInteractionResult::KIND_PATCH, $result->kind);
        self::assertSame('roundtrip-ok', $result->debug['value']);
        self::assertSame('uci_round_trip_99', $result->debug['instance']);
        // Validation (3 patches) + server-ack (1 patch).
        self::assertCount(4, $result->patches);
        foreach ($result->patches as $patch) {
            self::assertSame('uci_round_trip_99', $patch->targetInstance);
        }
    }

    #[Test]
    public function dispatcher_validates_patch_target_instance_against_signed_claims(): void
    {
        UiComponentRegistry::register(
            (new UiComponentMetadataFactory())->fromClass(EvilCrossInstancePatchComponent::class),
        );
        $ctx = SignedContext::sign([
            'c' => 'platform.test-evil-cross-instance',
            'i' => 'uci_legit_aaaaaaa',
            'p' => 'input',
            'e' => 'change',
            'u' => 'value',
        ]);

        $this->expectException(UiInteractionUnprocessableException::class);
        $this->expectExceptionMessageMatches('/different component instance/');
        $this->newDispatcher()->dispatch($ctx, $this->freshDispatchId(), []);
    }

    #[Test]
    public function production_like_with_non_shared_replay_store_throws_503(): void
    {
        // In-memory store reports isShared()=false. With productionLike
        // = true the dispatcher must refuse before touching the
        // replay store or invoking the handler.
        $dispatcher = $this->newDispatcher(productionLike: true);
        $this->expectException(UiInteractionConfigurationException::class);
        try {
            $dispatcher->dispatch($this->freshCtx(), $this->freshDispatchId(), ['value' => 'x']);
        } catch (UiInteractionConfigurationException $e) {
            self::assertSame(503, $e->httpStatus);
            self::assertSame('ui_replay_store_not_shared', $e->reason);
            // Safe message — no class FQCN, no connection string.
            self::assertStringNotContainsString('InMemory', $e->getMessage());
            self::assertStringNotContainsString('Semitexa\\', $e->getMessage());
            throw $e;
        }
    }

    #[Test]
    public function production_like_guard_runs_after_ctx_verification(): void
    {
        // Tampered ctx is a more specific failure than a config issue;
        // clients hitting a misconfigured server with a valid ctx see
        // the config error, but clients with a tampered ctx still see
        // 403. This ordering is part of the documented dispatch flow.
        $dispatcher = $this->newDispatcher(productionLike: true);
        $tampered = substr($this->freshCtx(), 0, -2) . 'AA';

        $this->expectException(UiInteractionForbiddenException::class);
        try {
            $dispatcher->dispatch($tampered, $this->freshDispatchId(), []);
        } catch (UiInteractionForbiddenException $e) {
            self::assertSame('invalid_signed_ctx', $e->reason);
            throw $e;
        }
    }

    #[Test]
    public function production_like_guard_does_not_invoke_handler_or_claim_replay(): void
    {
        // The replay store would normally see a claim on a successful
        // dispatch. The guard fires BEFORE that, so a re-attempt with
        // the same dispatchId on a NEWLY shared store (recovered config)
        // must still succeed — proof the unsafe store was never touched.
        $unsafeStore = new InMemoryUiReplayStore();
        $dispatcher = $this->newDispatcher(replayStore: $unsafeStore, productionLike: true);
        $ctx = $this->freshCtx();
        $id = $this->freshDispatchId();

        try {
            $dispatcher->dispatch($ctx, $id, ['value' => 'should-not-claim']);
            self::fail('Expected UiInteractionConfigurationException');
        } catch (UiInteractionConfigurationException) {
            // Expected — assert the guard short-circuited before the claim.
        }

        // A second dispatch on the SAME unsafe store with the SAME
        // dispatchId would normally return 409 (replay). Because the
        // guard short-circuited before the claim, the second call also
        // hits the guard, NOT a 409. Both attempts surface the config
        // error consistently — handlers stay un-invoked.
        $this->expectException(UiInteractionConfigurationException::class);
        $dispatcher->dispatch($ctx, $id, ['value' => 'still-blocked']);
    }

    #[Test]
    public function production_like_with_shared_replay_store_succeeds(): void
    {
        // Shared fake: claim/diagnostic stub reporting isShared()=true.
        $sharedStore = new class implements UiReplayStoreInterface {
            /** @var array<string, true> */
            private array $claimed = [];
            public function claim(string $key, int $ttlSeconds): bool
            {
                if (isset($this->claimed[$key])) return false;
                $this->claimed[$key] = true;
                return true;
            }
            public function isShared(): bool { return true; }
            public function diagnosticName(): string { return 'shared-test-double'; }
        };

        $dispatcher = $this->newDispatcher(replayStore: $sharedStore, productionLike: true);
        $result = $dispatcher->dispatch($this->freshCtx(), $this->freshDispatchId(), ['value' => 'ok']);
        self::assertSame('ok', $result->debug['value']);
    }

    #[Test]
    public function dev_environment_with_non_shared_store_still_dispatches(): void
    {
        // Existing developer flows: APP_ENV=dev + default in-memory
        // store. The guard MUST NOT block — dev workflow stays cheap.
        $dispatcher = $this->newDispatcher(productionLike: false);
        $result = $dispatcher->dispatch($this->freshCtx(), $this->freshDispatchId(), ['value' => 'dev-ok']);
        self::assertSame('dev-ok', $result->debug['value']);
    }

    #[Test]
    public function error_paths_do_not_expose_method_or_class_names(): void
    {
        $cases = [
            [$this->freshCtx(component: 'platform.nope'), [], UiInteractionNotFoundException::class],
            [$this->freshCtx(updates: 'other'), [], UiInteractionForbiddenException::class],
            ['sc1.aaa.bbb', [], UiInteractionForbiddenException::class],
        ];
        foreach ($cases as $case) {
            [$ctx, $payload, $expected] = $case;
            try {
                $this->newDispatcher()->dispatch($ctx, $this->freshDispatchId(), $payload);
                self::fail('Expected ' . $expected);
            } catch (\Throwable $e) {
                self::assertInstanceOf($expected, $e);
                self::assertStringNotContainsString('FieldComponent', $e->getMessage());
                self::assertStringNotContainsString('onInputChanged', $e->getMessage());
                self::assertStringNotContainsString('Semitexa\\PlatformUi', $e->getMessage());
            }
        }
    }
}

/**
 * Synthetic component whose handler attempts to patch a *different*
 * component instance than the one carried by the signed claims. The
 * dispatcher's UiPatchValidator must reject the dispatch.
 */
#[AsComponent(
    name: 'platform.test-evil-cross-instance',
    template: '@platform-ui/components/runtime/field.html.twig',
)]
#[UiPart(name: 'input', uses: InputPrimitive::class, bind: 'value')]
final class EvilCrossInstancePatchComponent
{
    #[UiOn(part: 'input', event: 'change')]
    public function onChange(UiInteractionEvent $event): UiInteractionResult
    {
        return UiInteractionResult::patch([
            new UiResponsePatch(
                op: UiResponsePatch::OP_SET_TEXT,
                targetInstance: 'uci_victim_999',
                targetPart: null,
                targetName: 'pwned',
                value: 'gotcha',
            ),
        ]);
    }
}
