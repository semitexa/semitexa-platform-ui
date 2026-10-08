<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Icon\IconRegistry;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\DescriptionListPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\DividerPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\KbdPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\MeterPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\ProgressPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\SegmentedPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\SkeletonPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\TagPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\PrimitiveRenderer;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveRegistry;
use Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\TwigFunction;

/** tk-br-display: the display primitives render native, accessible markup. */
final class DisplayPrimitivesRenderTest extends TestCase
{
    private PrimitiveRenderer $renderer;

    protected function setUp(): void
    {
        UiPrimitiveRegistry::reset();
        AssetCollectorStore::reset();
        $factory = new UiPrimitiveMetadataFactory();
        foreach ([ProgressPrimitive::class, MeterPrimitive::class, SkeletonPrimitive::class, DividerPrimitive::class, DescriptionListPrimitive::class, TagPrimitive::class, SegmentedPrimitive::class, KbdPrimitive::class] as $class) {
            UiPrimitiveRegistry::register($factory->fromClass($class));
        }
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2) . '/resources/twig', 'platform-ui');
        $twig = new TwigEnvironment($loader, ['cache' => false, 'autoescape' => 'html']);
        $twig->addFunction(new TwigFunction('icon', static fn (string $n, array $o = []): Markup => new Markup(IconRegistry::render($n, $o), 'UTF-8'), ['is_safe' => ['html']]));
        $this->renderer = new PrimitiveRenderer($twig);
    }

    protected function tearDown(): void
    {
        UiPrimitiveRegistry::reset();
        AssetCollectorStore::reset();
    }

    #[Test]
    public function progress_is_native_with_a_value_or_indeterminate(): void
    {
        $value = $this->renderer->render('progress', ['label' => 'Uploading', 'value' => 64, 'tone' => 'success']);
        self::assertStringContainsString('ui-tone="success"', $value);
        self::assertStringContainsString('<progress max="100" value="64" aria-label="Uploading"></progress>', $value);
        self::assertStringContainsString('<span ui-progress-value>64%</span>', $value);

        $busy = $this->renderer->render('progress', ['label' => 'Working']);
        self::assertStringContainsString('<progress max="100" aria-label="Working"></progress>', $busy, 'no value = indeterminate');
        self::assertStringNotContainsString('ui-progress-value', $busy);
    }

    #[Test]
    public function progress_survives_a_non_positive_max_and_clamps_its_caption(): void
    {
        $zeroMax = $this->renderer->render('progress', ['label' => 'Sync', 'value' => 5, 'max' => 0]);
        self::assertStringContainsString('<progress max="100" value="5" aria-label="Sync"></progress>', $zeroMax);
        self::assertStringContainsString('<span ui-progress-value>5%</span>', $zeroMax);

        $over = $this->renderer->render('progress', ['label' => 'Sync', 'value' => 150]);
        self::assertStringContainsString('<span ui-progress-value>100%</span>', $over);
        $under = $this->renderer->render('progress', ['label' => 'Sync', 'value' => -20]);
        self::assertStringContainsString('<span ui-progress-value>0%</span>', $under);
    }

    #[Test]
    public function meter_passes_its_thresholds_to_the_browser(): void
    {
        $html = $this->renderer->render('meter', ['label' => 'Disk', 'value' => 0.9, 'low' => 0.6, 'high' => 0.85, 'optimum' => 0.1]);
        self::assertStringContainsString('<meter value="0.9" min="0" max="1" low="0.6" high="0.85" optimum="0.1" aria-label="Disk"></meter>', $html);
    }

    #[Test]
    public function skeleton_divider_and_list(): void
    {
        $skeleton = $this->renderer->render('skeleton', ['lines' => 3]);
        self::assertStringContainsString('aria-hidden="true"', $skeleton);
        self::assertSame(3, substr_count($skeleton, '<span ui-skeleton-line></span>'));
        self::assertStringContainsString('style="--_w:3rem;"', $this->renderer->render('skeleton', ['shape' => 'circle', 'width' => '3rem']));

        self::assertStringStartsWith('<hr ui="divider"', $this->renderer->render('divider', []));
        self::assertStringContainsString('role="separator" aria-orientation="horizontal" ui-divider-label><span>or</span>', $this->renderer->render('divider', ['label' => 'or']));

        $list = $this->renderer->render('description-list', ['items' => [['term' => 'Name', 'details' => '<b>Ada</b>']]]);
        self::assertStringContainsString('<div><dt>Name</dt><dd>&lt;b&gt;Ada&lt;/b&gt;</dd></div>', $list);
    }

    #[Test]
    public function a_removable_tag_carries_its_form_value_and_an_accessible_remove_button(): void
    {
        $html = $this->renderer->render('tag', ['text' => 'Swoole', 'removable' => true, 'name' => 'tags', 'value' => 'swoole']);
        self::assertStringContainsString('ui-behavior="removable"', $html);
        self::assertStringContainsString('<input type="hidden" name="tags[]" value="swoole">', $html);
        self::assertStringContainsString('aria-label="Remove Swoole"', $html);
        self::assertStringContainsString('<svg', $html);
        $plain = $this->renderer->render('tag', ['text' => 'PHP']);
        self::assertStringContainsString('PHP', $plain);
        self::assertStringNotContainsString('ui-behavior', $plain);
    }

    #[Test]
    public function segmented_is_a_radio_group_and_kbd_lists_keys(): void
    {
        $html = $this->renderer->render('segmented', ['name' => 'view', 'label' => 'View', 'part' => 'input', 'value' => 'grid', 'options' => [['value' => 'list', 'label' => 'List'], ['value' => 'grid', 'label' => 'Grid']]]);
        self::assertStringContainsString('<fieldset ui="segmented" data-ui-primitive="platform.segmented" data-ui-part="input" aria-label="View">', $html);
        self::assertStringContainsString('<input type="radio" name="view" value="grid" checked>', $html);
        self::assertSame(1, substr_count($html, ' checked'));

        self::assertStringContainsString('<kbd>Ctrl</kbd><span aria-hidden="true">+</span><kbd>K</kbd>', $this->renderer->render('kbd', ['keys' => ['Ctrl', 'K']]));
    }
}
