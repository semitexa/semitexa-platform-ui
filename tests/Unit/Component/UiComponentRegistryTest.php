<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Component;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Component\Builtin\FieldComponent;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\InputPrimitive;
use Semitexa\PlatformUi\Attribute\UiOn;
use Semitexa\PlatformUi\Attribute\UiPart;
use Semitexa\PlatformUi\Domain\Model\Component\UiOnMetadata;
use Semitexa\Ssr\Attribute\AsComponent;

final class UiComponentRegistryTest extends TestCase
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
    public function get_binding_returns_metadata_for_registered_part_event(): void
    {
        UiComponentRegistry::register($this->factory->fromClass(FieldComponent::class));

        $binding = UiComponentRegistry::getBinding('platform.field', 'input', 'change');

        self::assertInstanceOf(UiOnMetadata::class, $binding);
        self::assertSame('platform.field', $binding->componentName);
        self::assertSame('input', $binding->partName);
        self::assertSame('change', $binding->eventName);
    }

    #[Test]
    public function get_binding_returns_null_for_unknown_component(): void
    {
        UiComponentRegistry::register($this->factory->fromClass(FieldComponent::class));

        self::assertNull(UiComponentRegistry::getBinding('platform.ghost', 'input', 'change'));
    }

    #[Test]
    public function get_binding_returns_null_for_unknown_part_on_known_component(): void
    {
        UiComponentRegistry::register($this->factory->fromClass(FieldComponent::class));

        self::assertNull(UiComponentRegistry::getBinding('platform.field', 'ghost', 'change'));
    }

    #[Test]
    public function get_binding_returns_null_for_unknown_event_on_known_part(): void
    {
        UiComponentRegistry::register($this->factory->fromClass(FieldComponent::class));

        self::assertNull(UiComponentRegistry::getBinding('platform.field', 'input', 'blur'));
    }

    #[Test]
    public function bindings_for_returns_all_events_in_declaration_order(): void
    {
        UiComponentRegistry::register($this->factory->fromClass(RegistryTwoBindingsComponent::class));

        $bindings = UiComponentRegistry::bindingsFor('platform.test-registry-two-bindings');

        self::assertCount(2, $bindings);
        self::assertSame('first', $bindings[0]->partName);
        self::assertSame('change', $bindings[0]->eventName);
        self::assertSame('second', $bindings[1]->partName);
        self::assertSame('blur', $bindings[1]->eventName);
    }

    #[Test]
    public function bindings_for_returns_empty_list_for_unknown_component(): void
    {
        self::assertSame([], UiComponentRegistry::bindingsFor('platform.ghost'));
    }

    #[Test]
    public function bindings_for_returns_empty_list_for_component_with_no_events(): void
    {
        UiComponentRegistry::register($this->factory->fromClass(RegistryNoBindingsComponent::class));

        self::assertSame([], UiComponentRegistry::bindingsFor('platform.test-registry-no-bindings'));
    }

    #[Test]
    public function bindings_are_isolated_across_components_with_overlapping_part_names(): void
    {
        UiComponentRegistry::register($this->factory->fromClass(RegistryComponentA::class));
        UiComponentRegistry::register($this->factory->fromClass(RegistryComponentB::class));

        $bindingA = UiComponentRegistry::getBinding('platform.test-registry-a', 'input', 'change');
        $bindingB = UiComponentRegistry::getBinding('platform.test-registry-b', 'input', 'change');

        self::assertNotNull($bindingA);
        self::assertNotNull($bindingB);
        self::assertSame(RegistryComponentA::class, $bindingA->class);
        self::assertSame(RegistryComponentB::class, $bindingB->class);
        self::assertSame('onA', $bindingA->methodName);
        self::assertSame('onB', $bindingB->methodName);
    }
}

#[AsComponent(name: 'platform.test-registry-two-bindings', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'first', uses: InputPrimitive::class)]
#[UiPart(name: 'second', uses: InputPrimitive::class)]
final class RegistryTwoBindingsComponent
{
    #[UiOn(part: 'first', event: 'change')]
    public function onFirstChange(array $event): void {}

    #[UiOn(part: 'second', event: 'blur')]
    public function onSecondBlur(array $event): void {}
}

#[AsComponent(name: 'platform.test-registry-no-bindings', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class)]
final class RegistryNoBindingsComponent {}

#[AsComponent(name: 'platform.test-registry-a', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class)]
final class RegistryComponentA
{
    #[UiOn(part: 'input', event: 'change')]
    public function onA(array $event): void {}
}

#[AsComponent(name: 'platform.test-registry-b', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class)]
final class RegistryComponentB
{
    #[UiOn(part: 'input', event: 'change')]
    public function onB(array $event): void {}
}
