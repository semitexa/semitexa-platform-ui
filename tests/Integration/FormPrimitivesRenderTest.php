<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\CheckboxPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\RadioPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\SelectPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\SwitchPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\TextareaPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\PrimitiveRenderer;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveRegistry;
use Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;

/** tk-br-form-primitives: the form controls as catalog primitives. */
final class FormPrimitivesRenderTest extends TestCase
{
    private PrimitiveRenderer $renderer;

    protected function setUp(): void
    {
        UiPrimitiveRegistry::reset();
        AssetCollectorStore::reset();
        $factory = new UiPrimitiveMetadataFactory();
        foreach ([SelectPrimitive::class, TextareaPrimitive::class, CheckboxPrimitive::class, RadioPrimitive::class, SwitchPrimitive::class] as $class) {
            UiPrimitiveRegistry::register($factory->fromClass($class));
        }
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2) . '/resources/twig', 'platform-ui');
        $this->renderer = new PrimitiveRenderer(new TwigEnvironment($loader, ['cache' => false, 'autoescape' => 'html']));
    }

    protected function tearDown(): void
    {
        UiPrimitiveRegistry::reset();
        AssetCollectorStore::reset();
    }

    #[Test]
    public function a_select_renders_its_options_value_and_part(): void
    {
        $html = $this->renderer->render('select', [
            'name' => 'status', 'part' => 'input', 'placeholder' => 'Choose…', 'value' => 'b',
            'options' => [['value' => 'a', 'label' => 'A & co'], ['value' => 'b', 'label' => 'B'], ['value' => 'c', 'label' => 'C', 'disabled' => true]],
        ]);

        self::assertStringContainsString('<select ui="select" data-ui-primitive="platform.select" data-ui-part="input" name="status"', $html);
        self::assertStringContainsString('<option value="">Choose…</option>', $html);
        self::assertStringContainsString('<option value="b" selected>B</option>', $html);
        self::assertStringContainsString('<option value="c" disabled>C</option>', $html);
        self::assertStringContainsString('A &amp; co', $html);
    }

    #[Test]
    public function a_multiple_select_submits_a_list_and_has_no_placeholder(): void
    {
        $html = $this->renderer->render('select', [
            'name' => 'tags', 'multiple' => true, 'placeholder' => 'x', 'value' => ['a', 'c'],
            'options' => [['value' => 'a'], ['value' => 'b'], ['value' => 'c']],
        ]);

        self::assertStringContainsString('name="tags[]"', $html);
        self::assertStringContainsString(' multiple', $html);
        self::assertStringNotContainsString('<option value="">', $html);
        self::assertSame(2, substr_count($html, ' selected'));
    }

    #[Test]
    public function a_textarea_escapes_its_value(): void
    {
        $html = $this->renderer->render('textarea', ['name' => 'body', 'rows' => 3, 'value' => '</textarea><script>']);

        self::assertStringContainsString('rows="3"', $html);
        self::assertStringContainsString('&lt;/textarea&gt;&lt;script&gt;</textarea>', $html);
    }

    #[Test]
    public function choices_are_a_label_around_the_native_control(): void
    {
        $switch = $this->renderer->render('switch', ['name' => 'dark', 'label' => 'Dark mode', 'checked' => true, 'part' => 'input']);
        self::assertMatchesRegularExpression('#^<label ui="switch" data-ui-primitive="platform.switch">\s*<input type="checkbox" role="switch" value="1" data-ui-part="input" name="dark" checked>#', $switch);
        self::assertStringContainsString('<span ui-choice-label>Dark mode</span></label>', $switch);

        $radio = $this->renderer->render('radio', ['name' => 'plan', 'value' => 'pro']);
        self::assertStringContainsString('<input type="radio" value="pro" name="plan">', $radio);

        $checkbox = $this->renderer->render('checkbox', ['name' => 'terms']);
        self::assertStringContainsString('<input type="checkbox" value="1" name="terms">', $checkbox);
        self::assertStringNotContainsString('role="switch"', $checkbox);
    }
}
