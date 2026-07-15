<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Component\Builtin\CardComponent;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * Renders the presentational CardComponent through a real Twig environment
 * with only the `slot()` helper wired — card has no parts, no events, so the
 * harness stays minimal (no primitive/manifest/part machinery needed).
 */
final class CardComponentRenderTest extends TestCase
{
    private TwigEnvironment $twig;

    protected function setUp(): void
    {
        UiComponentRegistry::reset();
        UiComponentRegistry::register(
            (new UiComponentMetadataFactory())->fromClass(CardComponent::class),
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
    private function renderCard(array $props = [], array $slots = []): string
    {
        return $this->twig->render(
            '@platform-ui/components/runtime/card.html.twig',
            array_merge($props, [
                '_component' => ['name' => 'platform.card', 'class' => CardComponent::class],
                '_slots' => $slots,
            ]),
        );
    }

    #[Test]
    public function card_metadata_registers_four_slots_and_no_parts(): void
    {
        $meta = UiComponentRegistry::get('platform.card');
        self::assertNotNull($meta);
        $slotNames = array_keys($meta->slots);
        sort($slotNames);
        self::assertSame(['body', 'footer', 'header', 'media'], $slotNames);
        self::assertSame([], $meta->parts);
    }

    #[Test]
    public function renders_body_slot_inside_a_card_root(): void
    {
        $html = $this->renderCard([], ['body' => '<p data-test="b">Hello</p>']);

        self::assertStringContainsString('data-ui-component="platform.card"', $html);
        self::assertStringContainsString('ui-component="card"', $html);
        self::assertStringContainsString('<div ui-card="body">', $html);
        self::assertStringContainsString('<p data-test="b">Hello</p>', $html);
    }

    #[Test]
    public function title_prop_renders_a_default_header_when_no_header_slot(): void
    {
        $html = $this->renderCard(['title' => 'Invoices', 'subtitle' => 'March 2026']);

        self::assertStringContainsString('<div ui-card="header">', $html);
        self::assertStringContainsString('<div ui-card="title">Invoices</div>', $html);
        self::assertStringContainsString('<div ui-card="subtitle">March 2026</div>', $html);
    }

    #[Test]
    public function header_slot_wins_over_title_prop(): void
    {
        $html = $this->renderCard(
            ['title' => 'ignored'],
            ['header' => '<h2 data-test="h">Custom header</h2>'],
        );

        self::assertStringContainsString('<h2 data-test="h">Custom header</h2>', $html);
        self::assertStringNotContainsString('ui-card="title"', $html);
        self::assertStringNotContainsString('ignored', $html);
    }

    #[Test]
    public function variant_prop_lands_on_root_and_defaults_are_omitted(): void
    {
        $outlined = $this->renderCard(['variant' => 'outlined'], ['body' => 'x']);
        self::assertStringContainsString('ui-variant="outlined"', $outlined);

        // No variant prop => no attribute (CSS treats bare == elevated).
        $bare = $this->renderCard([], ['body' => 'x']);
        self::assertStringNotContainsString('ui-variant=', $bare);
    }

    #[Test]
    public function empty_slots_and_no_title_render_only_the_bare_root(): void
    {
        $html = trim($this->renderCard());

        self::assertStringContainsString('data-ui-component="platform.card"', $html);
        self::assertStringNotContainsString('ui-card="header"', $html);
        self::assertStringNotContainsString('ui-card="body"', $html);
        self::assertStringNotContainsString('ui-card="footer"', $html);
        self::assertStringNotContainsString('ui-card="media"', $html);
    }

    #[Test]
    public function media_header_body_footer_render_in_source_order(): void
    {
        $html = $this->renderCard(
            [],
            [
                'media' => '<img data-test="m" src="/x.png" alt="">',
                'header' => '<span data-test="h">H</span>',
                'body' => '<span data-test="b">B</span>',
                'footer' => '<span data-test="f">F</span>',
            ],
        );

        $m = strpos($html, 'data-test="m"');
        $h = strpos($html, 'data-test="h"');
        $b = strpos($html, 'data-test="b"');
        $f = strpos($html, 'data-test="f"');
        self::assertNotFalse($m);
        self::assertNotFalse($f);
        self::assertLessThan($h, $m, 'media before header');
        self::assertLessThan($b, $h, 'header before body');
        self::assertLessThan($f, $b, 'body before footer');
    }
}
