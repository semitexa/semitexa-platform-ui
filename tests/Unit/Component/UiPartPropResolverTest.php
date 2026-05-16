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
use Semitexa\Ssr\Attribute\AsComponent;

final class UiPartPropResolverTest extends TestCase
{
    private UiComponentMetadataFactory $factory;
    private UiPartPropResolver $resolver;

    protected function setUp(): void
    {
        $this->factory = new UiComponentMetadataFactory();
        $this->resolver = new UiPartPropResolver();
    }

    #[Test]
    public function part_defaults_apply_when_no_provider_no_overrides(): void
    {
        $metadata = $this->factory->fromClass(DefaultsOnlyComponent::class);

        $props = $this->resolver->resolve($metadata, 'input', []);

        self::assertSame('text', $props['type']);
        self::assertSame('default-value', $props['value']);
    }

    #[Test]
    public function provider_result_overrides_part_defaults(): void
    {
        $metadata = $this->factory->fromClass(ProviderOverridesDefaultsComponent::class);

        $props = $this->resolver->resolve($metadata, 'input', ['value' => 'hello']);

        self::assertSame('email', $props['type'], 'provider type wins over default text');
        self::assertSame('hello', $props['value'], 'provider passes value through');
    }

    #[Test]
    public function caller_overrides_win_over_provider_result(): void
    {
        $metadata = $this->factory->fromClass(ProviderOverridesDefaultsComponent::class);

        $props = $this->resolver->resolve(
            $metadata,
            'input',
            ['value' => 'hello'],
            ['type' => 'password', 'value' => 'override'],
        );

        self::assertSame('password', $props['type']);
        self::assertSame('override', $props['value']);
    }

    #[Test]
    public function explicit_overrides_can_introduce_keys_outside_provider(): void
    {
        $metadata = $this->factory->fromClass(DefaultsOnlyComponent::class);

        $props = $this->resolver->resolve(
            $metadata,
            'input',
            [],
            ['autocomplete' => 'off'],
        );

        self::assertSame('off', $props['autocomplete']);
    }

    #[Test]
    public function unknown_part_is_rejected(): void
    {
        $metadata = $this->factory->fromClass(DefaultsOnlyComponent::class);

        $this->expectException(UiComponentRegistryException::class);
        $this->expectExceptionMessageMatches('/has no part named "ghost"/');

        $this->resolver->resolve($metadata, 'ghost', []);
    }

    #[Test]
    public function provider_returning_non_array_is_rejected(): void
    {
        $metadata = $this->factory->fromClass(BadProviderReturnComponent::class);

        $this->expectException(UiComponentRegistryException::class);
        $this->expectExceptionMessageMatches('/must return array/');

        $this->resolver->resolve($metadata, 'input', []);
    }

    #[Test]
    public function provider_can_be_invoked_with_explicit_component_instance(): void
    {
        $metadata = $this->factory->fromClass(StatefulProviderComponent::class);

        // Spy: explicit instance returns a marker so we can assert
        // the resolver used THIS instance rather than instantiating a fresh one.
        $instance = new StatefulProviderComponent('spied');

        $props = $this->resolver->resolve($metadata, 'input', [], [], $instance);

        self::assertSame('spied', $props['marker']);
    }

    #[Test]
    public function field_component_resolves_input_part_through_provider(): void
    {
        $metadata = $this->factory->fromClass(FieldComponent::class);

        $props = $this->resolver->resolve($metadata, 'input', [
            'name' => 'email',
            'placeholder' => 'name@example.com',
            'help' => 'We never share email.',
        ]);

        self::assertSame('email', $props['name']);
        self::assertSame('email', $props['id'], 'id falls back to name');
        self::assertSame('text', $props['type']);
        self::assertSame('name@example.com', $props['placeholder']);
        self::assertSame('email-help', $props['aria_describedby']);
        self::assertNull($props['state']);
        self::assertNull($props['aria_invalid']);
    }

    #[Test]
    public function field_component_input_part_sets_invalid_state_on_error(): void
    {
        $metadata = $this->factory->fromClass(FieldComponent::class);

        $props = $this->resolver->resolve($metadata, 'input', [
            'name' => 'username',
            'value' => 'me!',
            'help' => 'Should be ignored when error present.',
            'error' => 'Letters and digits only.',
        ]);

        self::assertSame('invalid', $props['state']);
        self::assertTrue($props['aria_invalid']);
        self::assertSame('username-error', $props['aria_describedby']);
    }

    #[Test]
    public function field_component_inputprops_caller_overrides_win(): void
    {
        $metadata = $this->factory->fromClass(FieldComponent::class);

        $props = $this->resolver->resolve(
            $metadata,
            'input',
            ['name' => 'email'],
            ['placeholder' => 'forced', 'autocomplete' => 'off'],
        );

        self::assertSame('forced', $props['placeholder']);
        self::assertSame('off', $props['autocomplete']);
        // Provider-resolved keys still present:
        self::assertSame('email', $props['name']);
    }
}

#[AsComponent(name: 'platform.test-defaults-only', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class, defaults: ['type' => 'text', 'value' => 'default-value'])]
final class DefaultsOnlyComponent {}

#[AsComponent(name: 'platform.test-provider-overrides', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class, defaults: ['type' => 'text', 'value' => 'default-value'])]
final class ProviderOverridesDefaultsComponent
{
    /**
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    #[ProvidesUiPart(part: 'input')]
    public function inputPart(array $props): array
    {
        return [
            'type' => 'email',
            'value' => $props['value'] ?? 'fallback',
        ];
    }
}

#[AsComponent(name: 'platform.test-bad-return', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class)]
final class BadProviderReturnComponent
{
    /** No declared return type — factory cannot reject at metadata time
     *  (only typed returns are checked), so the resolver enforces
     *  is_array() at call time. */
    #[ProvidesUiPart(part: 'input')]
    public function inputPart(array $props)
    {
        return 'not-an-array';
    }
}

#[AsComponent(name: 'platform.test-stateful-provider', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class)]
final class StatefulProviderComponent
{
    public function __construct(public readonly string $marker = 'auto') {}

    /**
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    #[ProvidesUiPart(part: 'input')]
    public function inputPart(array $props): array
    {
        return ['marker' => $this->marker];
    }
}
