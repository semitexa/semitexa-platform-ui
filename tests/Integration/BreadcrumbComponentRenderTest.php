<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Component\Builtin\BreadcrumbComponent;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * Renders the data-driven BreadcrumbComponent. The only slot is `trailing`,
 * so the harness wires `slot()` plus the registered metadata.
 */
final class BreadcrumbComponentRenderTest extends TestCase
{
    private TwigEnvironment $twig;

    protected function setUp(): void
    {
        UiComponentRegistry::reset();
        UiComponentRegistry::register(
            (new UiComponentMetadataFactory())->fromClass(BreadcrumbComponent::class),
        );

        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2) . '/resources/twig', 'platform-ui');
        $this->twig = new TwigEnvironment($loader, [
            'cache' => false,
            'strict_variables' => false,
            'autoescape' => 'html',
        ]);

        $this->twig->addFunction(new TwigFunction(
            'slot',
            static function (array $context, string $name): Markup {
                $slots = $context['_slots'] ?? [];
                $value = is_array($slots) ? ($slots[$name] ?? null) : null;
                return new Markup($value === null ? '' : (string) $value, 'UTF-8');
            },
            ['needs_context' => true, 'is_safe' => ['html']],
        ));
    }

    protected function tearDown(): void
    {
        UiComponentRegistry::reset();
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $slots
     */
    private function render(array $props = [], array $slots = []): string
    {
        return $this->twig->render(
            '@platform-ui/components/runtime/breadcrumb.html.twig',
            array_merge($props, [
                '_component' => ['name' => 'platform.breadcrumb', 'class' => BreadcrumbComponent::class],
                '_slots' => $slots,
            ]),
        );
    }

    #[Test]
    public function registers_with_the_trailing_slot_and_no_parts(): void
    {
        // The `trailing` slot is what makes a data-driven component
        // discoverable — discovery keys off #[UiPart]/#[UiSlot].
        $meta = UiComponentRegistry::get('platform.breadcrumb');
        self::assertNotNull($meta);
        self::assertSame(['trailing'], array_keys($meta->slots));
        self::assertSame([], $meta->parts);
    }

    #[Test]
    public function trailing_slot_renders_after_the_trail(): void
    {
        $html = $this->render(
            ['items' => [['label' => 'Home', 'href' => '/'], ['label' => 'Now']]],
            ['trailing' => '<button data-test="act">Copy</button>'],
        );

        self::assertStringContainsString('<div ui-crumbs-trailing>', $html);
        self::assertStringContainsString('<button data-test="act">Copy</button>', $html);
        $olPos = strpos($html, '</ol>');
        $actPos = strpos($html, 'data-test="act"');
        self::assertNotFalse($olPos);
        self::assertNotFalse($actPos);
        self::assertLessThan($actPos, $olPos, 'Trailing actions must render after the </ol>.');
    }

    #[Test]
    public function without_trailing_slot_no_trailing_wrapper_is_emitted(): void
    {
        $html = $this->render(['items' => [['label' => 'X']]]);
        self::assertStringNotContainsString('ui-crumbs-trailing', $html);
    }

    #[Test]
    public function renders_semantic_nav_ol_with_default_aria_label(): void
    {
        $html = $this->render(['items' => [
            ['label' => 'Home', 'href' => '/'],
            ['label' => 'Reports'],
        ]]);

        self::assertStringContainsString('data-ui-component="platform.breadcrumb"', $html);
        self::assertStringContainsString('<nav', $html);
        self::assertStringContainsString('aria-label="Breadcrumb"', $html);
        self::assertStringContainsString('<ol ui-crumbs>', $html);
    }

    #[Test]
    public function intermediate_item_with_href_is_a_link(): void
    {
        $html = $this->render(['items' => [
            ['label' => 'Home', 'href' => '/'],
            ['label' => 'Reports', 'href' => '/reports'],
            ['label' => 'March'],
        ]]);

        self::assertStringContainsString('<a href="/">Home</a>', $html);
        self::assertStringContainsString('<a href="/reports">Reports</a>', $html);
    }

    #[Test]
    public function last_item_is_current_page_and_never_a_link(): void
    {
        $html = $this->render(['items' => [
            ['label' => 'Home', 'href' => '/'],
            ['label' => 'March', 'href' => '/should-be-ignored'],
        ]]);

        self::assertStringContainsString('<span aria-current="page">March</span>', $html);
        // Even though the last item carries an href, it must not become a link.
        self::assertStringNotContainsString('/should-be-ignored', $html);
    }

    #[Test]
    public function item_without_href_renders_as_plain_span(): void
    {
        $html = $this->render(['items' => [
            ['label' => 'Docs'],
            ['label' => 'Guide'],
        ]]);

        self::assertStringContainsString('<span>Docs</span>', $html);
        self::assertStringContainsString('<span aria-current="page">Guide</span>', $html);
        self::assertStringNotContainsString('<a ', $html);
    }

    #[Test]
    public function custom_aria_label_is_respected(): void
    {
        $html = $this->render(['ariaLabel' => 'You are here', 'items' => [['label' => 'X']]]);
        self::assertStringContainsString('aria-label="You are here"', $html);
    }

    #[Test]
    public function empty_items_render_an_empty_trail_not_an_error(): void
    {
        $html = $this->render();
        self::assertStringContainsString('<ol ui-crumbs>', $html);
        self::assertStringNotContainsString('<li', $html);
    }
}
