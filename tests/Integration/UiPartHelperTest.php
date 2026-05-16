<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Component\Builtin\FieldComponent;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Semitexa\PlatformUi\Application\Service\Component\UiPartPropResolver;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\BadgePrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\ButtonPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\InputPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\PrimitiveRenderer;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveRegistry;
use Semitexa\PlatformUi\Attribute\UiPart;
use Semitexa\PlatformUi\Domain\Exception\UiComponentRegistryException;
use Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore;
use Semitexa\Ssr\Attribute\AsComponent;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * Direct-render tests for the `ui_part(partName, overrides)` Twig helper:
 * verifies the marker is injected on the root tag, never duplicated, and
 * tolerates each built-in primitive's tag shape.
 */
final class UiPartHelperTest extends TestCase
{
    private TwigEnvironment $twig;

    protected function setUp(): void
    {
        UiPrimitiveRegistry::reset();
        UiComponentRegistry::reset();
        AssetCollectorStore::reset();

        UiPrimitiveRegistry::register(
            (new UiPrimitiveMetadataFactory())->fromClass(InputPrimitive::class),
        );
        UiPrimitiveRegistry::register(
            (new UiPrimitiveMetadataFactory())->fromClass(ButtonPrimitive::class),
        );
        UiPrimitiveRegistry::register(
            (new UiPrimitiveMetadataFactory())->fromClass(BadgePrimitive::class),
        );
        UiComponentRegistry::register(
            (new UiComponentMetadataFactory())->fromClass(FieldComponent::class),
        );
        UiComponentRegistry::register(
            (new UiComponentMetadataFactory())->fromClass(AliasMismatchComponent::class),
        );

        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2) . '/resources/twig', 'platform-ui');
        $this->twig = new TwigEnvironment($loader, [
            'cache' => false,
            'strict_variables' => false,
            'autoescape' => 'html',
        ]);

        $renderer = new PrimitiveRenderer($this->twig);
        $this->twig->addFunction(new TwigFunction(
            'primitive',
            static fn (string $name, array $props = []): Markup =>
                new Markup($renderer->render($name, $props), 'UTF-8'),
            ['is_safe' => ['html']],
        ));

        $resolver = new UiPartPropResolver();
        $this->twig->addFunction(new TwigFunction(
            'ui_part',
            static function (array $context, string $partName, array $overrides = []) use ($resolver, $renderer): Markup {
                $component = $context['_component'] ?? null;
                $metadata = UiComponentRegistry::get($component['name']);
                $part = $metadata->part($partName);
                $componentProps = [];
                foreach ($context as $key => $value) {
                    if (is_string($key) && $key !== '' && $key[0] !== '_') {
                        $componentProps[$key] = $value;
                    }
                }
                $resolved = $resolver->resolve($metadata, $partName, $componentProps, $overrides);
                $html = $renderer->render($part->primitiveName, $resolved);
                $markerAttr = sprintf(
                    'data-ui-part="%s"',
                    htmlspecialchars($partName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                );
                $count = 0;
                $injected = preg_replace(
                    '/^(\s*<[a-zA-Z][a-zA-Z0-9-]*)(?=\s|>|\/>)/',
                    '$1 ' . str_replace('\\', '\\\\', str_replace('$', '\\$', $markerAttr)),
                    $html,
                    1,
                    $count,
                );
                if ($injected === null || $count !== 1) {
                    throw new UiComponentRegistryException('marker injection failed');
                }
                return new Markup($injected, 'UTF-8');
            },
            ['needs_context' => true, 'is_safe' => ['html']],
        ));
    }

    protected function tearDown(): void
    {
        UiPrimitiveRegistry::reset();
        UiComponentRegistry::reset();
        AssetCollectorStore::reset();
    }

    /**
     * @param array<string, mixed> $props
     */
    private function renderUiPart(string $componentName, string $partName, array $props = [], array $overrides = []): string
    {
        $context = array_merge($props, [
            '_component' => ['name' => $componentName],
            '_slots' => [],
        ]);
        $template = $this->twig->createTemplate(
            '{{ ui_part("' . addslashes($partName) . '", overrides|default({})) }}',
        );
        return $template->render(array_merge($context, ['overrides' => $overrides]));
    }

    #[Test]
    public function injects_data_ui_part_on_input_root(): void
    {
        $html = $this->renderUiPart('platform.field', 'input', [
            'name' => 'email',
            'value' => 'taras@example.com',
        ]);

        // Marker is the FIRST attribute after the tag name on the root.
        self::assertMatchesRegularExpression(
            '/^\s*<input\s+data-ui-part="input"\s/',
            $html,
        );
        self::assertStringContainsString('value="taras@example.com"', $html);
        self::assertSame(1, substr_count($html, 'data-ui-part="input"'));
    }

    #[Test]
    public function injects_marker_when_part_name_differs_from_primitive_ui_alias(): void
    {
        // AliasMismatchComponent has a part named "main" but uses InputPrimitive
        // whose ui alias is "input". The marker MUST follow the part name,
        // not the ui alias — that's the whole point of this slice.
        $html = $this->renderUiPart('platform.test-alias-mismatch', 'main', [
            'name' => 'q',
        ]);

        self::assertMatchesRegularExpression('/^\s*<input\s+data-ui-part="main"\s/', $html);
        self::assertStringContainsString('ui="input"', $html, 'primitive ui alias still present');
        self::assertStringContainsString('data-ui-primitive="platform.input"', $html);
        // Make sure the runtime selector [data-ui-part="main"] would match.
        self::assertStringContainsString('data-ui-part="main"', $html);
    }

    #[Test]
    public function marker_is_emitted_exactly_once_per_render(): void
    {
        $html = $this->renderUiPart('platform.field', 'input', ['name' => 'a']);
        self::assertSame(1, substr_count($html, 'data-ui-part="input"'));
    }

    #[Test]
    public function unknown_part_raises_clear_error(): void
    {
        $this->expectException(\Throwable::class);
        $this->renderUiPart('platform.field', 'ghost');
    }

    #[Test]
    public function marker_does_not_replace_existing_ui_attribute(): void
    {
        $html = $this->renderUiPart('platform.field', 'input', ['name' => 'a']);
        // Both attributes must coexist on the root element.
        self::assertStringContainsString('data-ui-part="input"', $html);
        self::assertStringContainsString('ui="input"', $html);
    }
}

#[AsComponent(name: 'platform.test-alias-mismatch', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'main', uses: InputPrimitive::class)]
final class AliasMismatchComponent {}
