<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Component;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Component\Builtin\FieldComponent;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\InputPrimitive;
use Semitexa\PlatformUi\Attribute\UiPart;
use Semitexa\PlatformUi\Attribute\UiSlot;
use Semitexa\PlatformUi\Domain\Exception\UiComponentRegistryException;
use Semitexa\Ssr\Attribute\AsComponent;

final class UiComponentMetadataFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        UiComponentRegistry::reset();
    }

    #[Test]
    public function extracts_parts_and_slots_from_field_component(): void
    {
        $metadata = (new UiComponentMetadataFactory())->fromClass(FieldComponent::class);

        self::assertSame('platform.field', $metadata->name);
        self::assertSame(FieldComponent::class, $metadata->class);

        self::assertArrayHasKey('input', $metadata->parts);
        self::assertSame(InputPrimitive::class, $metadata->parts['input']->uses);

        self::assertArrayHasKey('prefix', $metadata->slots);
        self::assertArrayHasKey('suffix', $metadata->slots);
    }

    #[Test]
    public function fqcn_in_uses_is_required(): void
    {
        $this->expectException(UiComponentRegistryException::class);
        $this->expectExceptionMessageMatches('/references missing class/');

        (new UiComponentMetadataFactory())->fromClass(BadUsesComponent::class);
    }

    #[Test]
    public function uses_target_must_be_a_primitive(): void
    {
        $this->expectException(UiComponentRegistryException::class);
        $this->expectExceptionMessageMatches('/not marked with #\[AsUiPrimitive\]/');

        (new UiComponentMetadataFactory())->fromClass(NonPrimitiveUsesComponent::class);
    }

    #[Test]
    public function class_without_as_component_is_rejected(): void
    {
        $this->expectException(UiComponentRegistryException::class);
        $this->expectExceptionMessageMatches('/is not marked with #\[AsComponent\]/');

        (new UiComponentMetadataFactory())->fromClass(MissingAsComponent::class);
    }

    #[Test]
    public function duplicate_part_name_is_rejected(): void
    {
        $this->expectException(UiComponentRegistryException::class);
        $this->expectExceptionMessageMatches('/declares part "input" more than once/');

        (new UiComponentMetadataFactory())->fromClass(DuplicatePartComponent::class);
    }

    #[Test]
    public function duplicate_slot_name_is_rejected(): void
    {
        $this->expectException(UiComponentRegistryException::class);
        $this->expectExceptionMessageMatches('/declares slot "suffix" more than once/');

        (new UiComponentMetadataFactory())->fromClass(DuplicateSlotComponent::class);
    }

    #[Test]
    public function registry_records_field_component(): void
    {
        UiComponentRegistry::reset();
        UiComponentRegistry::register(
            (new UiComponentMetadataFactory())->fromClass(FieldComponent::class),
        );

        $field = UiComponentRegistry::get('platform.field');
        self::assertNotNull($field);
        self::assertTrue(UiComponentRegistry::has('platform.field'));
        self::assertCount(1, $field->parts);
        self::assertCount(2, $field->slots);
    }
}

#[AsComponent(name: 'platform.bad-uses', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: 'Semitexa\\PlatformUi\\No\\Such\\Class')]
final class BadUsesComponent {}

#[AsComponent(name: 'platform.non-primitive-uses', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: \stdClass::class)]
final class NonPrimitiveUsesComponent {}

#[UiPart(name: 'input', uses: InputPrimitive::class)]
final class MissingAsComponent {}

#[AsComponent(name: 'platform.duplicate-part', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class)]
#[UiPart(name: 'input', uses: InputPrimitive::class)]
final class DuplicatePartComponent {}

#[AsComponent(name: 'platform.duplicate-slot', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiSlot(name: 'suffix')]
#[UiSlot(name: 'suffix')]
final class DuplicateSlotComponent {}
