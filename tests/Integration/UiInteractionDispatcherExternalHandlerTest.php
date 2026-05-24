<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Semitexa\PlatformUi\Application\Service\Event\AllowAllUiInteractionAuthorizer;
use Semitexa\PlatformUi\Application\Service\Event\InMemoryUiReplayStore;
use Semitexa\PlatformUi\Application\Service\Event\UiInteractionDispatcher;
use Semitexa\PlatformUi\Application\Service\Event\UiPatchValidator;
use Semitexa\PlatformUi\Application\Service\Event\UiPayloadFieldGuard;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\InputPrimitive;
use Semitexa\PlatformUi\Attribute\HandlesUiEvent;
use Semitexa\PlatformUi\Attribute\UiPart;
use Semitexa\PlatformUi\Attribute\UiSlot;
use Semitexa\PlatformUi\Contract\UiEventHandlerInterface;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionConfigurationException;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionForbiddenException;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionNotFoundException;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionUnprocessableException;
use Semitexa\PlatformUi\Domain\Model\Component\UiComponentMetadata;
use Semitexa\PlatformUi\Domain\Model\Component\UiExternalHandlerMetadata;
use Semitexa\PlatformUi\Domain\Model\Component\UiOnMetadata;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventError;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventResponse;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionEvent;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionResult;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;
use Semitexa\Ssr\Attribute\AsComponent;

final class UiInteractionDispatcherExternalHandlerTest extends TestCase
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
        putenv('APP_SECRET=platform-ui-dispatcher-external-test');
        putenv('APP_ENV=dev');

        UiComponentRegistry::reset();
        $factory = new UiComponentMetadataFactory();
        UiComponentRegistry::register($factory->fromClass(ExtDispGridFixtureComponent::class));
        UiComponentRegistry::registerExternalFromClass(ExtDispGridFilterHandler::class);
        UiComponentRegistry::registerExternalFromClass(ExtDispGridSortHandler::class);
        UiComponentRegistry::registerExternalFromClass(ExtDispGridErrorHandler::class);

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

    #[Test]
    public function dispatch_routes_to_handles_ui_event_service_handler(): void
    {
        $handler = new ExtDispGridFilterHandler();
        $dispatcher = $this->newDispatcher(
            resolver: fn (string $fqcn): UiEventHandlerInterface => $handler,
        );

        $result = $dispatcher->dispatch(
            $this->freshCtx(part: 'filters', event: 'submit'),
            $this->freshDispatchId(),
            ['q' => 'acme'],
        );

        self::assertSame(UiInteractionResult::KIND_ACK, $result->kind);
        self::assertSame('ok', $result->debug['status']);
        self::assertSame(['q' => 'acme'], $handler->capturedPayload);
        self::assertNotNull($handler->capturedContext);
        self::assertSame('filters.submit', $handler->capturedContext->semanticEvent);
        self::assertSame('uci_ext_disp_test_001', $handler->capturedContext->signedClaims['i']);
    }

    #[Test]
    public function dispatch_passes_dp_claim_through_to_context(): void
    {
        $handler = new ExtDispGridFilterHandler();
        $dispatcher = $this->newDispatcher(
            resolver: fn (string $fqcn): UiEventHandlerInterface => $handler,
        );

        $dispatcher->dispatch(
            $this->freshCtx(part: 'filters', event: 'submit', dp: ExtDispGridFakeDp::class),
            $this->freshDispatchId(),
            ['q' => 'with-dp'],
        );

        self::assertNotNull($handler->capturedContext);
        self::assertSame(ExtDispGridFakeDp::class, $handler->capturedContext->dataProviderClass());
        self::assertSame(ExtDispGridFakeDp::class, $handler->capturedContext->signedClaims['dp']);
    }

    #[Test]
    public function dispatch_falls_back_to_404_when_neither_method_nor_external_binding_exists(): void
    {
        $dispatcher = $this->newDispatcher();

        $this->expectException(UiInteractionNotFoundException::class);
        $this->expectExceptionMessageMatches('/has no #\[UiOn\] or #\[HandlesUiEvent\] binding/');

        $dispatcher->dispatch(
            $this->freshCtx(part: 'rows', event: 'ghost'),
            $this->freshDispatchId(),
            [],
        );
    }

    #[Test]
    public function dispatch_accepts_slot_target_as_valid_part_or_slot(): void
    {
        $handler = new ExtDispGridFilterHandler();
        $dispatcher = $this->newDispatcher(
            resolver: fn (string $fqcn): UiEventHandlerInterface => $handler,
        );

        // The fixture component declares 'filters' as a #[UiSlot], not a #[UiPart].
        // The pre-Phase-5 dispatcher would have 404'd ('unknown_part'); now it routes.
        $result = $dispatcher->dispatch(
            $this->freshCtx(part: 'filters', event: 'submit'),
            $this->freshDispatchId(),
            ['q' => 'slot-ok'],
        );

        self::assertSame(UiInteractionResult::KIND_ACK, $result->kind);
    }

    #[Test]
    public function dispatch_skips_updates_path_check_for_service_handler(): void
    {
        // The signed ctx omits the 'u' claim entirely. A method-level
        // #[UiOn] would have hit the updates_path_mismatch 403 if its
        // metadata declared an updates path; the service-handler branch
        // skips that check by design (UX concern, not security).
        $handler = new ExtDispGridFilterHandler();
        $dispatcher = $this->newDispatcher(
            resolver: fn (string $fqcn): UiEventHandlerInterface => $handler,
        );

        $result = $dispatcher->dispatch(
            $this->freshCtx(part: 'filters', event: 'submit', updates: null),
            $this->freshDispatchId(),
            ['q' => 'no-updates-claim'],
        );

        self::assertSame(UiInteractionResult::KIND_ACK, $result->kind);
    }

    #[Test]
    public function dispatch_carries_correlation_and_state_through_debug(): void
    {
        $handler = new ExtDispGridSortHandler();
        $dispatcher = $this->newDispatcher(
            resolver: fn (string $fqcn): UiEventHandlerInterface => $handler,
        );

        $result = $dispatcher->dispatch(
            $this->freshCtx(part: 'rows', event: 'sort'),
            $this->freshDispatchId(),
            ['col' => 'name', 'dir' => 'asc'],
        );

        self::assertSame(UiInteractionResult::KIND_ACK, $result->kind);
        self::assertSame('grid-sort-001', $result->debug['correlationId']);
        self::assertSame(
            ['rows' => [['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']]],
            $result->debug['state'],
        );
    }

    #[Test]
    public function dispatch_maps_error_response_to_422(): void
    {
        $dispatcher = $this->newDispatcher(
            resolver: fn (string $fqcn): UiEventHandlerInterface => new ExtDispGridErrorHandler(),
        );

        $this->expectException(UiInteractionUnprocessableException::class);
        $this->expectExceptionMessage('Filter query is too short.');

        $dispatcher->dispatch(
            $this->freshCtx(part: 'filters', event: 'error-test'),
            $this->freshDispatchId(),
            ['q' => 'x'],
        );
    }

    #[Test]
    public function dispatch_throws_configuration_when_resolver_is_missing(): void
    {
        $dispatcher = $this->newDispatcher(resolver: null);

        $this->expectException(UiInteractionConfigurationException::class);
        $this->expectExceptionMessageMatches('/handler resolver/');

        $dispatcher->dispatch(
            $this->freshCtx(part: 'filters', event: 'submit'),
            $this->freshDispatchId(),
            ['q' => 'no-resolver'],
        );
    }

    #[Test]
    public function dispatch_unprocessable_when_resolver_returns_wrong_type(): void
    {
        $dispatcher = $this->newDispatcher(
            resolver: fn (string $fqcn) => new \stdClass(),
        );

        $this->expectException(UiInteractionUnprocessableException::class);
        $this->expectExceptionMessageMatches('/not a UiEventHandlerInterface/');

        $dispatcher->dispatch(
            $this->freshCtx(part: 'filters', event: 'submit'),
            $this->freshDispatchId(),
            ['q' => 'wrong-type'],
        );
    }

    #[Test]
    public function authorizer_external_denial_returns_403(): void
    {
        $deny = new class implements \Semitexa\PlatformUi\Application\Service\Event\UiInteractionAuthorizerInterface {
            public function authorize(UiInteractionEvent $event, UiComponentMetadata $component, UiOnMetadata $eventMeta): bool
            {
                return true;
            }
            public function authorizeExternal(UiInteractionEvent $event, UiComponentMetadata $component, UiExternalHandlerMetadata $externalMeta): bool
            {
                return false;
            }
        };

        $dispatcher = $this->newDispatcher(
            authorizer: $deny,
            resolver: fn (string $fqcn): UiEventHandlerInterface => new ExtDispGridFilterHandler(),
        );

        $this->expectException(UiInteractionForbiddenException::class);
        $this->expectExceptionMessage('Authorization policy denied this UI interaction.');

        $dispatcher->dispatch(
            $this->freshCtx(part: 'filters', event: 'submit'),
            $this->freshDispatchId(),
            ['q' => 'denied'],
        );
    }

    private function newDispatcher(
        ?\Semitexa\PlatformUi\Application\Service\Event\UiInteractionAuthorizerInterface $authorizer = null,
        ?\Closure $resolver = null,
    ): UiInteractionDispatcher {
        return new UiInteractionDispatcher(
            payloadGuard: new UiPayloadFieldGuard(),
            patchValidator: new UiPatchValidator(),
            replayStore: new InMemoryUiReplayStore(),
            authorizer: $authorizer ?? new AllowAllUiInteractionAuthorizer(),
            productionLike: false,
            handlerResolver: $resolver,
        );
    }

    private function freshCtx(
        string $instance = 'uci_ext_disp_test_001',
        string $part = 'filters',
        string $event = 'submit',
        ?string $updates = null,
        ?string $dp = null,
    ): string {
        $claims = [
            'c' => 'platform.test-ext-disp-fixture',
            'i' => $instance,
            'p' => $part,
            'e' => $event,
        ];
        if ($updates !== null) {
            $claims['u'] = $updates;
        }
        if ($dp !== null) {
            $claims['dp'] = $dp;
        }
        return SignedContext::sign($claims);
    }

    private function freshDispatchId(): string
    {
        $this->dispatchSeq++;
        return sprintf('ui_evt_%032s', dechex(($this->dispatchSeq << 16) | random_int(0, 0xFFFF)));
    }
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

#[AsComponent(name: 'platform.test-ext-disp-fixture', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'rows', uses: InputPrimitive::class)]
#[UiSlot(name: 'filters', description: 'caller-provided filter form')]
final class ExtDispGridFixtureComponent {}

#[HandlesUiEvent(component: ExtDispGridFixtureComponent::class, part: 'filters', event: 'submit')]
final class ExtDispGridFilterHandler implements UiEventHandlerInterface
{
    /** @var array<string, mixed>|null */
    public ?array $capturedPayload = null;
    public ?UiEventContext $capturedContext = null;

    public function handle(object $payload, UiEventContext $context): UiEventResponse
    {
        // payload is (object) of the request array — cast back for assertions.
        $this->capturedPayload = (array) $payload;
        $this->capturedContext = $context;
        return UiEventResponse::ok();
    }
}

#[HandlesUiEvent(component: ExtDispGridFixtureComponent::class, part: 'rows', event: 'sort')]
final class ExtDispGridSortHandler implements UiEventHandlerInterface
{
    public function handle(object $payload, UiEventContext $context): UiEventResponse
    {
        return UiEventResponse::patch(
            state: ['rows' => [['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']]],
            correlationId: 'grid-sort-001',
        );
    }
}

#[HandlesUiEvent(component: ExtDispGridFixtureComponent::class, part: 'filters', event: 'error-test')]
final class ExtDispGridErrorHandler implements UiEventHandlerInterface
{
    public function handle(object $payload, UiEventContext $context): UiEventResponse
    {
        return UiEventResponse::error(new UiEventError(
            code: 'filter_too_short',
            message: 'Filter query is too short.',
        ));
    }
}

final class ExtDispGridFakeDp implements \Semitexa\PlatformUi\Contract\UiPartDataProviderInterface
{
    public function provide(\Semitexa\PlatformUi\Domain\Model\Component\UiPartContext $context): array
    {
        return [];
    }
}
