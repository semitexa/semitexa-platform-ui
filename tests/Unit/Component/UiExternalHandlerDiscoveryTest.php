<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Component;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\InputPrimitive;
use Semitexa\PlatformUi\Attribute\HandlesUiEvent;
use Semitexa\PlatformUi\Attribute\UiOn;
use Semitexa\PlatformUi\Attribute\UiPart;
use Semitexa\PlatformUi\Attribute\UiSlot;
use Semitexa\PlatformUi\Contract\UiEventHandlerInterface;
use Semitexa\PlatformUi\Domain\Exception\UiComponentRegistryException;
use Semitexa\PlatformUi\Domain\Model\Component\UiExternalHandlerMetadata;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventResponse;
use Semitexa\Ssr\Attribute\AsComponent;

final class UiExternalHandlerDiscoveryTest extends TestCase
{
    private UiComponentMetadataFactory $factory;

    protected function setUp(): void
    {
        UiComponentRegistry::reset();
        $this->factory = new UiComponentMetadataFactory();
    }

    protected function tearDown(): void
    {
        UiComponentRegistry::reset();
    }

    #[Test]
    public function discovers_external_handler_and_makes_it_retrievable(): void
    {
        $this->registerComponent(ExtDiscPartComponent::class);
        UiComponentRegistry::registerExternalFromClass(ExtDiscPartHandler::class);

        $binding = UiComponentRegistry::getExternalBinding('platform.test-ext-part', 'input', 'submit');

        self::assertInstanceOf(UiExternalHandlerMetadata::class, $binding);
        self::assertSame('platform.test-ext-part', $binding->componentName);
        self::assertSame(ExtDiscPartComponent::class, $binding->componentClass);
        self::assertSame('input', $binding->partName);
        self::assertSame('submit', $binding->eventName);
        self::assertSame(ExtDiscPartHandler::class, $binding->handlerClass);
        self::assertNull($binding->payloadClass);
    }

    #[Test]
    public function handler_targeting_slot_is_accepted(): void
    {
        $this->registerComponent(ExtDiscSlotComponent::class);
        UiComponentRegistry::registerExternalFromClass(ExtDiscSlotHandler::class);

        $binding = UiComponentRegistry::getExternalBinding('platform.test-ext-slot', 'filters', 'submit');

        self::assertNotNull($binding);
        self::assertSame('filters', $binding->partName);
    }

    #[Test]
    public function multiple_bindings_on_one_handler_class_all_register(): void
    {
        $this->registerComponent(ExtDiscMultiTargetComponent::class);
        UiComponentRegistry::registerExternalFromClass(ExtDiscMultiHandler::class);

        $bindings = UiComponentRegistry::externalBindingsFor('platform.test-ext-multi');

        self::assertCount(3, $bindings);
        self::assertSame(['submit', 'sort', 'paginate'], array_map(
            static fn (UiExternalHandlerMetadata $b): string => $b->eventName,
            $bindings,
        ));
    }

    #[Test]
    public function external_bindings_for_returns_empty_for_unknown_component(): void
    {
        self::assertSame([], UiComponentRegistry::externalBindingsFor('platform.unknown'));
    }

    #[Test]
    public function get_external_binding_returns_null_for_unknown_triple(): void
    {
        $this->registerComponent(ExtDiscPartComponent::class);
        UiComponentRegistry::registerExternalFromClass(ExtDiscPartHandler::class);

        self::assertNull(UiComponentRegistry::getExternalBinding('platform.test-ext-part', 'input', 'blur'));
        self::assertNull(UiComponentRegistry::getExternalBinding('platform.test-ext-part', 'ghost', 'submit'));
        self::assertNull(UiComponentRegistry::getExternalBinding('platform.ghost', 'input', 'submit'));
    }

    #[Test]
    public function handler_not_implementing_interface_is_rejected(): void
    {
        $this->registerComponent(ExtDiscPartComponent::class);

        $this->expectException(UiComponentRegistryException::class);
        $this->expectExceptionMessageMatches('/does not implement/');

        UiComponentRegistry::registerExternalFromClass(ExtDiscBadInterfaceHandler::class);
    }

    #[Test]
    public function component_class_that_does_not_exist_is_rejected(): void
    {
        $this->expectException(UiComponentRegistryException::class);
        $this->expectExceptionMessageMatches('/that class does not exist/');

        UiComponentRegistry::registerExternalFromClass(ExtDiscUnknownComponentHandler::class);
    }

    #[Test]
    public function component_class_exists_but_is_not_registered_is_rejected(): void
    {
        $this->expectException(UiComponentRegistryException::class);
        $this->expectExceptionMessageMatches('/is not a registered Platform UI component/');

        UiComponentRegistry::registerExternalFromClass(ExtDiscUnregisteredComponentHandler::class);
    }

    #[Test]
    public function unknown_part_name_is_rejected(): void
    {
        $this->registerComponent(ExtDiscPartComponent::class);

        $this->expectException(UiComponentRegistryException::class);
        $this->expectExceptionMessageMatches('/no #\[UiPart\] or #\[UiSlot\] of that name is declared/');

        UiComponentRegistry::registerExternalFromClass(ExtDiscBadPartHandler::class);
    }

    #[Test]
    public function invalid_event_name_is_rejected(): void
    {
        $this->registerComponent(ExtDiscPartComponent::class);

        $this->expectException(UiComponentRegistryException::class);
        $this->expectExceptionMessageMatches('/invalid event name/');

        UiComponentRegistry::registerExternalFromClass(ExtDiscBadEventHandler::class);
    }

    #[Test]
    public function missing_payload_class_is_rejected(): void
    {
        $this->registerComponent(ExtDiscPartComponent::class);

        $this->expectException(UiComponentRegistryException::class);
        $this->expectExceptionMessageMatches('/payload class does not exist/');

        UiComponentRegistry::registerExternalFromClass(ExtDiscMissingPayloadHandler::class);
    }

    #[Test]
    public function collision_with_method_level_ui_on_is_rejected(): void
    {
        $this->registerComponent(ExtDiscMethodAndExternalComponent::class);

        $this->expectException(UiComponentRegistryException::class);
        $this->expectExceptionMessageMatches('/already bound to method/');

        UiComponentRegistry::registerExternalFromClass(ExtDiscMethodCollidingHandler::class);
    }

    #[Test]
    public function duplicate_class_level_binding_is_rejected(): void
    {
        $this->registerComponent(ExtDiscPartComponent::class);
        UiComponentRegistry::registerExternalFromClass(ExtDiscPartHandler::class);

        $this->expectException(UiComponentRegistryException::class);
        $this->expectExceptionMessageMatches('/Duplicate #\[HandlesUiEvent\] binding/');

        UiComponentRegistry::registerExternalFromClass(ExtDiscDuplicateBindingHandler::class);
    }

    #[Test]
    public function reset_clears_external_bindings(): void
    {
        $this->registerComponent(ExtDiscPartComponent::class);
        UiComponentRegistry::registerExternalFromClass(ExtDiscPartHandler::class);

        UiComponentRegistry::reset();

        self::assertSame([], UiComponentRegistry::externalBindingsFor('platform.test-ext-part'));
        self::assertNull(UiComponentRegistry::getExternalBinding('platform.test-ext-part', 'input', 'submit'));
    }

    private function registerComponent(string $class): void
    {
        UiComponentRegistry::register($this->factory->fromClass($class));
    }
}

#[AsComponent(name: 'platform.test-ext-part', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class)]
final class ExtDiscPartComponent {}

#[HandlesUiEvent(component: ExtDiscPartComponent::class, part: 'input', event: 'submit')]
final class ExtDiscPartHandler implements UiEventHandlerInterface
{
    public function handle(object $payload, UiEventContext $context): UiEventResponse
    {
        return UiEventResponse::ok();
    }
}

#[AsComponent(name: 'platform.test-ext-slot', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class)]
#[UiSlot(name: 'filters', description: 'caller-provided filter form')]
final class ExtDiscSlotComponent {}

#[HandlesUiEvent(component: ExtDiscSlotComponent::class, part: 'filters', event: 'submit')]
final class ExtDiscSlotHandler implements UiEventHandlerInterface
{
    public function handle(object $payload, UiEventContext $context): UiEventResponse
    {
        return UiEventResponse::ok();
    }
}

#[AsComponent(name: 'platform.test-ext-multi', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'rows', uses: InputPrimitive::class)]
#[UiSlot(name: 'filters', description: 'filter form')]
final class ExtDiscMultiTargetComponent {}

#[HandlesUiEvent(component: ExtDiscMultiTargetComponent::class, part: 'filters', event: 'submit')]
#[HandlesUiEvent(component: ExtDiscMultiTargetComponent::class, part: 'rows', event: 'sort')]
#[HandlesUiEvent(component: ExtDiscMultiTargetComponent::class, part: 'rows', event: 'paginate')]
final class ExtDiscMultiHandler implements UiEventHandlerInterface
{
    public function handle(object $payload, UiEventContext $context): UiEventResponse
    {
        return UiEventResponse::ok();
    }
}

#[HandlesUiEvent(component: ExtDiscPartComponent::class, part: 'input', event: 'submit')]
final class ExtDiscBadInterfaceHandler
{
    public function handle(object $payload, UiEventContext $context): UiEventResponse
    {
        return UiEventResponse::ok();
    }
}

#[HandlesUiEvent(component: \Semitexa\PlatformUi\Tests\Unit\Component\NoSuchClass::class, part: 'input', event: 'submit')]
final class ExtDiscUnknownComponentHandler implements UiEventHandlerInterface
{
    public function handle(object $payload, UiEventContext $context): UiEventResponse
    {
        return UiEventResponse::ok();
    }
}

#[HandlesUiEvent(component: ExtDiscPartComponent::class, part: 'ghost', event: 'submit')]
final class ExtDiscBadPartHandler implements UiEventHandlerInterface
{
    public function handle(object $payload, UiEventContext $context): UiEventResponse
    {
        return UiEventResponse::ok();
    }
}

#[HandlesUiEvent(component: ExtDiscPartComponent::class, part: 'input', event: 'Submit')]
final class ExtDiscBadEventHandler implements UiEventHandlerInterface
{
    public function handle(object $payload, UiEventContext $context): UiEventResponse
    {
        return UiEventResponse::ok();
    }
}

#[HandlesUiEvent(component: ExtDiscPartComponent::class, part: 'input', event: 'submit', payload: \Semitexa\PlatformUi\Tests\Unit\Component\NoSuchPayload::class)]
final class ExtDiscMissingPayloadHandler implements UiEventHandlerInterface
{
    public function handle(object $payload, UiEventContext $context): UiEventResponse
    {
        return UiEventResponse::ok();
    }
}

#[AsComponent(name: 'platform.test-ext-method-and-external', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class)]
final class ExtDiscMethodAndExternalComponent
{
    #[UiOn(part: 'input', event: 'submit')]
    public function onSubmit(array $event): void {}
}

#[HandlesUiEvent(component: ExtDiscMethodAndExternalComponent::class, part: 'input', event: 'submit')]
final class ExtDiscMethodCollidingHandler implements UiEventHandlerInterface
{
    public function handle(object $payload, UiEventContext $context): UiEventResponse
    {
        return UiEventResponse::ok();
    }
}

#[HandlesUiEvent(component: ExtDiscPartComponent::class, part: 'input', event: 'submit')]
final class ExtDiscDuplicateBindingHandler implements UiEventHandlerInterface
{
    public function handle(object $payload, UiEventContext $context): UiEventResponse
    {
        return UiEventResponse::ok();
    }
}

// Class exists but is NOT registered as a Platform UI component (no
// #[AsComponent] + no #[UiPart]/#[UiSlot]). Used to exercise the
// "exists but not registered" rejection branch.
final class ExtDiscUnregisteredComponent {}

#[HandlesUiEvent(component: ExtDiscUnregisteredComponent::class, part: 'input', event: 'submit')]
final class ExtDiscUnregisteredComponentHandler implements UiEventHandlerInterface
{
    public function handle(object $payload, UiEventContext $context): UiEventResponse
    {
        return UiEventResponse::ok();
    }
}
