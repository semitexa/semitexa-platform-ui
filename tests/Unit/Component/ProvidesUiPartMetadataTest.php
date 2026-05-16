<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Component;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Component\Builtin\FieldComponent;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\InputPrimitive;
use Semitexa\PlatformUi\Attribute\ProvidesUiPart;
use Semitexa\PlatformUi\Attribute\UiPart;
use Semitexa\PlatformUi\Domain\Exception\UiComponentRegistryException;
use Semitexa\Ssr\Attribute\AsComponent;

final class ProvidesUiPartMetadataTest extends TestCase
{
    private UiComponentMetadataFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new UiComponentMetadataFactory();
    }

    #[Test]
    public function field_component_exposes_input_provider_metadata(): void
    {
        $metadata = $this->factory->fromClass(FieldComponent::class);

        $provider = $metadata->provider('input');
        self::assertNotNull($provider);
        self::assertSame('input', $provider->part);
        self::assertSame(FieldComponent::class, $provider->class);
        self::assertSame('inputPart', $provider->method);
    }

    #[Test]
    public function provider_referencing_unknown_part_is_rejected(): void
    {
        $this->expectException(UiComponentRegistryException::class);
        $this->expectExceptionMessageMatches('/no #\[UiPart\(name: "ghost"\)\] is declared/');

        $this->factory->fromClass(ProviderForUnknownPartComponent::class);
    }

    #[Test]
    public function two_providers_for_same_part_are_rejected(): void
    {
        $this->expectException(UiComponentRegistryException::class);
        $this->expectExceptionMessageMatches('/declares more than one #\[ProvidesUiPart\(part: "input"\)\]/');

        $this->factory->fromClass(TwoProvidersSamePartComponent::class);
    }

    #[Test]
    public function provider_with_non_array_return_type_is_rejected(): void
    {
        $this->expectException(UiComponentRegistryException::class);
        $this->expectExceptionMessageMatches('/must declare return type `array`/');

        $this->factory->fromClass(WrongReturnTypeProviderComponent::class);
    }

    #[Test]
    public function static_provider_is_rejected(): void
    {
        $this->expectException(UiComponentRegistryException::class);
        $this->expectExceptionMessageMatches('/must not be static/');

        $this->factory->fromClass(StaticProviderComponent::class);
    }
}

#[AsComponent(name: 'platform.test-unknown-part', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class)]
final class ProviderForUnknownPartComponent
{
    /** @return array<string, mixed> */
    #[ProvidesUiPart(part: 'ghost')]
    public function ghostPart(array $props): array { return []; }
}

#[AsComponent(name: 'platform.test-dup-providers', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class)]
final class TwoProvidersSamePartComponent
{
    /** @return array<string, mixed> */
    #[ProvidesUiPart(part: 'input')]
    public function first(array $props): array { return []; }

    /** @return array<string, mixed> */
    #[ProvidesUiPart(part: 'input')]
    public function second(array $props): array { return []; }
}

#[AsComponent(name: 'platform.test-wrong-return', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class)]
final class WrongReturnTypeProviderComponent
{
    #[ProvidesUiPart(part: 'input')]
    public function inputPart(array $props): string { return ''; }
}

#[AsComponent(name: 'platform.test-static-provider', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class)]
final class StaticProviderComponent
{
    /** @return array<string, mixed> */
    #[ProvidesUiPart(part: 'input')]
    public static function inputPart(array $props): array { return []; }
}
