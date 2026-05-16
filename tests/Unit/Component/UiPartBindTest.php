<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Component;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Component\Builtin\FieldComponent;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Component\UiPartPropResolver;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\InputPrimitive;
use Semitexa\PlatformUi\Attribute\ProvidesUiPart;
use Semitexa\PlatformUi\Attribute\UiPart;
use Semitexa\PlatformUi\Domain\Exception\UiComponentRegistryException;
use Semitexa\PlatformUi\Domain\Model\Component\UiValuePath;
use Semitexa\Ssr\Attribute\AsComponent;

final class UiPartBindTest extends TestCase
{
    private UiComponentMetadataFactory $factory;
    private UiPartPropResolver $resolver;

    protected function setUp(): void
    {
        $this->factory = new UiComponentMetadataFactory();
        $this->resolver = new UiPartPropResolver();
    }

    #[Test]
    public function field_component_input_part_declares_bind_value(): void
    {
        $metadata = $this->factory->fromClass(FieldComponent::class);
        $part = $metadata->part('input');

        self::assertNotNull($part);
        self::assertInstanceOf(UiValuePath::class, $part->bind);
        self::assertSame('value', (string) $part->bind);
    }

    #[Test]
    public function metadata_keeps_bind_null_when_no_bind_declared(): void
    {
        $metadata = $this->factory->fromClass(UnboundPartComponent::class);
        $part = $metadata->part('input');

        self::assertNotNull($part);
        self::assertNull($part->bind);
    }

    #[Test]
    public function factory_validates_nested_bind_path(): void
    {
        $metadata = $this->factory->fromClass(NestedBoundComponent::class);
        $part = $metadata->part('input');

        self::assertNotNull($part);
        self::assertNotNull($part->bind);
        self::assertSame('user.email', (string) $part->bind);
        self::assertTrue($part->bind->isNested());
    }

    #[Test]
    public function factory_rejects_invalid_bind_path(): void
    {
        $this->expectException(UiComponentRegistryException::class);
        $this->expectExceptionMessageMatches('/declares invalid bind/');

        $this->factory->fromClass(InvalidBindComponent::class);
    }

    #[Test]
    public function bind_derived_value_lands_on_resolved_part_props(): void
    {
        $metadata = $this->factory->fromClass(BoundOnlyComponent::class);

        $props = $this->resolver->resolve($metadata, 'input', ['value' => 'hello']);

        self::assertSame('hello', $props['value']);
    }

    #[Test]
    public function bind_derived_value_overrides_provider_value(): void
    {
        $metadata = $this->factory->fromClass(ProviderAndBoundComponent::class);

        $props = $this->resolver->resolve($metadata, 'input', ['value' => 'from-bind']);

        self::assertSame('from-bind', $props['value']);
        // Provider-only key is still present (proves provider ran):
        self::assertSame('marker', $props['name']);
    }

    #[Test]
    public function caller_overrides_win_over_bind_derived_value(): void
    {
        $metadata = $this->factory->fromClass(ProviderAndBoundComponent::class);

        $props = $this->resolver->resolve(
            $metadata,
            'input',
            ['value' => 'from-bind'],
            ['value' => 'forced-by-caller'],
        );

        self::assertSame('forced-by-caller', $props['value']);
    }

    #[Test]
    public function missing_bind_value_does_not_clobber_provider_value(): void
    {
        $metadata = $this->factory->fromClass(ProviderAndBoundComponent::class);

        $props = $this->resolver->resolve($metadata, 'input', [/* no 'value' */]);

        self::assertSame('provider-fallback', $props['value']);
    }

    #[Test]
    public function nested_bind_walks_caller_props(): void
    {
        $metadata = $this->factory->fromClass(NestedBoundComponent::class);

        $props = $this->resolver->resolve($metadata, 'input', [
            'user' => ['email' => 'taras@example.com'],
        ]);

        self::assertSame('taras@example.com', $props['value']);
    }

    #[Test]
    public function nested_bind_missing_segment_returns_provider_value(): void
    {
        $metadata = $this->factory->fromClass(NestedBoundWithProviderComponent::class);

        $props = $this->resolver->resolve($metadata, 'input', [/* no user */]);

        self::assertSame('provider-fallback', $props['value']);
    }

    #[Test]
    public function field_component_renders_bound_value_via_resolver(): void
    {
        $metadata = $this->factory->fromClass(FieldComponent::class);

        $props = $this->resolver->resolve($metadata, 'input', [
            'name' => 'email',
            'value' => 'hello@example.com',
        ]);

        self::assertSame('email', $props['name']);
        self::assertSame('hello@example.com', $props['value']);
    }

    #[Test]
    public function field_component_inputprops_value_wins_over_bind(): void
    {
        $metadata = $this->factory->fromClass(FieldComponent::class);

        $props = $this->resolver->resolve(
            $metadata,
            'input',
            ['name' => 'email', 'value' => 'from-bind'],
            ['value' => 'forced-by-inputProps'],
        );

        self::assertSame('forced-by-inputProps', $props['value']);
    }

    #[Test]
    public function field_component_missing_value_leaves_value_unset_in_resolved_props(): void
    {
        $metadata = $this->factory->fromClass(FieldComponent::class);

        $props = $this->resolver->resolve($metadata, 'input', ['name' => 'email']);

        // Provider no longer projects 'value'; bind step yields null and
        // therefore leaves the key absent. The input primitive template
        // treats absent value as "no value attribute".
        self::assertArrayNotHasKey('value', $props);
    }
}

#[AsComponent(name: 'platform.test-unbound', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class)]
final class UnboundPartComponent {}

#[AsComponent(name: 'platform.test-invalid-bind', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class, bind: 'user..email')]
final class InvalidBindComponent {}

#[AsComponent(name: 'platform.test-bind-only', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class, bind: 'value')]
final class BoundOnlyComponent {}

#[AsComponent(name: 'platform.test-provider-and-bind', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class, bind: 'value')]
final class ProviderAndBoundComponent
{
    /** @param array<string, mixed> $props
     *  @return array<string, mixed> */
    #[ProvidesUiPart(part: 'input')]
    public function inputPart(array $props): array
    {
        return ['name' => 'marker', 'value' => 'provider-fallback'];
    }
}

#[AsComponent(name: 'platform.test-nested-bind', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class, bind: 'user.email')]
final class NestedBoundComponent {}

#[AsComponent(name: 'platform.test-nested-bind-fallback', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class, bind: 'user.email')]
final class NestedBoundWithProviderComponent
{
    /** @param array<string, mixed> $props
     *  @return array<string, mixed> */
    #[ProvidesUiPart(part: 'input')]
    public function inputPart(array $props): array
    {
        return ['value' => 'provider-fallback'];
    }
}
