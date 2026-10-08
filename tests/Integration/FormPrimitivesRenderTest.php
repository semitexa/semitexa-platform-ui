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
use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;

/** tk-br-form-primitives: the form controls as catalog primitives. */
final class FormPrimitivesRenderTest extends TestCase
{
    private PrimitiveRenderer $renderer;

    /** The process-wide primitive catalog and asset collector as the test found them. */
    private mixed $catalogBefore = null;
    private mixed $assetsBefore = null;

    protected function setUp(): void
    {
        // Swap in fresh ones instead of reset(): reset() empties the shared
        // objects in place, so whatever another test registered would be lost.
        $this->catalogBefore = self::swap(UiPrimitiveRegistry::class, 'catalog', null);
        $this->assetsBefore = self::swap(AssetCollectorStore::class, 'staticFallback', null);
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
        self::swap(UiPrimitiveRegistry::class, 'catalog', $this->catalogBefore);
        self::swap(AssetCollectorStore::class, 'staticFallback', $this->assetsBefore);
    }

    /** @param class-string $class */
    private static function swap(string $class, string $property, mixed $value): mixed
    {
        $reflection = new \ReflectionProperty($class, $property);
        $previous = $reflection->getValue();
        $reflection->setValue(null, $value);

        return $previous;
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

    /**
     * Outside a field nothing points a <label> at the select (a toolbar filter,
     * a sort order), so the primitive itself must carry the accessible name.
     * The Workbench audit found every select example nameless.
     */
    #[Test]
    public function a_select_outside_a_field_takes_its_accessible_name_from_label(): void
    {
        $contract = (new \ReflectionClass(SelectPrimitive::class))->getAttributes(AsUiContract::class)[0]->newInstance();
        $props = ['name' => 'sort', 'label' => 'Sort by', 'options' => [['value' => 'new', 'label' => 'Newest']]];

        UiProp::validateObject($contract->props, $props, 'select');
        $html = $this->renderer->render('select', $props);

        self::assertStringContainsString('<select ui="select" data-ui-primitive="platform.select" name="sort" aria-label="Sort by"', $html);
        self::assertSame(1, substr_count($html, 'Sort by'), 'the label names the control; it is not an option');
    }

    #[Test]
    public function every_select_example_has_an_accessible_name(): void
    {
        $contract = (new \ReflectionClass(SelectPrimitive::class))->getAttributes(AsUiContract::class)[0]->newInstance()->metadata();
        $labels = [];
        foreach ($contract->examples as $example) {
            $html = $this->renderer->render('select', $example->props);
            $labels[$example->name] = preg_match('/<select[^>]* aria-label="([^"]+)"/', $html, $m) === 1 ? $m[1] : null;
        }

        self::assertSame(['default' => 'Status', 'selected' => 'Status', 'multiple' => 'Tags'], $labels);
    }

    #[Test]
    public function the_contract_declares_the_list_a_multiple_select_takes(): void
    {
        $contract = (new \ReflectionClass(SelectPrimitive::class))->getAttributes(AsUiContract::class)[0]->newInstance();
        $props = ['name' => 'tags', 'multiple' => true, 'values' => ['a', 'c'], 'options' => [['value' => 'a'], ['value' => 'b'], ['value' => 'c']]];

        UiProp::validateObject($contract->props, $props, 'select');
        $html = $this->renderer->render('select', $props);

        self::assertSame(2, substr_count($html, ' selected'));
        self::assertStringContainsString('<option value="a" selected>a</option>', $html);
        self::assertStringContainsString('<option value="c" selected>c</option>', $html);
    }

    #[Test]
    public function a_checked_control_that_failed_validation_keeps_the_danger_border(): void
    {
        $css = (string) file_get_contents(\dirname(__DIR__, 2) . '/resources/primitives/choice.css');
        $invalid = strpos($css, '> input[aria-invalid="true"] { border-color: var(--ui-state-danger); }');
        preg_match_all('/> input:checked \{[^}]*border-color/', $css, $checked, PREG_OFFSET_CAPTURE);

        self::assertNotFalse($invalid);
        self::assertCount(3, $checked[0], 'checkbox, radio and switch each colour their checked border');
        // Equal specificity: the later rule wins, so invalid must come last.
        self::assertGreaterThan(max(array_column($checked[0], 1)), $invalid);
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
