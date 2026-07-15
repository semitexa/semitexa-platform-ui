<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Component\Builtin\NavbarComponent;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\TwigFunction;

final class NavbarComponentRenderTest extends TestCase
{
    private TwigEnvironment $twig;

    protected function setUp(): void
    {
        UiComponentRegistry::reset();
        UiComponentRegistry::register(
            (new UiComponentMetadataFactory())->fromClass(NavbarComponent::class),
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
            '@platform-ui/components/runtime/navbar.html.twig',
            array_merge($props, [
                '_component' => ['name' => 'platform.navbar', 'class' => NavbarComponent::class],
                '_slots' => $slots,
            ]),
        );
    }

    #[Test]
    public function registers_brand_and_actions_slots(): void
    {
        $meta = UiComponentRegistry::get('platform.navbar');
        self::assertNotNull($meta);
        $slots = array_keys($meta->slots);
        sort($slots);
        self::assertSame(['actions', 'brand'], $slots);
    }

    #[Test]
    public function renders_brand_slot_items_and_actions_in_order(): void
    {
        $html = $this->render(
            ['items' => [
                ['label' => 'Home', 'href' => '/', 'current' => true],
                ['label' => 'Docs', 'href' => '/docs'],
            ]],
            [
                'brand' => '<span data-test="brand">Acme</span>',
                'actions' => '<button data-test="act">Sign in</button>',
            ],
        );

        self::assertStringContainsString('data-ui-component="platform.navbar"', $html);
        self::assertStringContainsString('aria-label="Main"', $html);
        self::assertStringContainsString('<a href="/" aria-current="page">Home</a>', $html);
        self::assertStringContainsString('<a href="/docs">Docs</a>', $html);

        $brand = strpos($html, 'data-test="brand"');
        $home = strpos($html, '>Home<');
        $act = strpos($html, 'data-test="act"');
        self::assertLessThan($home, $brand, 'brand before links');
        self::assertLessThan($act, $home, 'links before actions');
    }

    #[Test]
    public function item_without_href_is_a_plain_span(): void
    {
        $html = $this->render(['items' => [['label' => 'Static', 'current' => true]]]);
        self::assertStringContainsString('<span aria-current="page">Static</span>', $html);
        self::assertStringNotContainsString('<a ', $html);
    }

    #[Test]
    public function no_items_renders_no_list(): void
    {
        $html = $this->render([], ['brand' => 'X']);
        self::assertStringNotContainsString('ui-navbar="items"', $html);
    }

    #[Test]
    public function custom_aria_label_is_respected(): void
    {
        $html = $this->render(['ariaLabel' => 'Primary', 'items' => [['label' => 'A', 'href' => '/a']]]);
        self::assertStringContainsString('aria-label="Primary"', $html);
    }
}
