<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Component\Builtin\PaginationComponent;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\TwigFunction;

final class PaginationComponentRenderTest extends TestCase
{
    private TwigEnvironment $twig;

    protected function setUp(): void
    {
        UiComponentRegistry::reset();
        UiComponentRegistry::register(
            (new UiComponentMetadataFactory())->fromClass(PaginationComponent::class),
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
            '@platform-ui/components/runtime/pagination.html.twig',
            array_merge($props, [
                '_component' => ['name' => 'platform.pagination', 'class' => PaginationComponent::class],
                '_slots' => $slots,
            ]),
        );
    }

    #[Test]
    public function registers_summary_slot(): void
    {
        $meta = UiComponentRegistry::get('platform.pagination');
        self::assertNotNull($meta);
        self::assertSame(['summary'], array_keys($meta->slots));
    }

    #[Test]
    public function current_page_is_marked_and_not_a_link(): void
    {
        $html = $this->render(['current' => 2, 'total' => 3, 'hrefTemplate' => '/list?page={page}']);

        self::assertStringContainsString('data-ui-component="platform.pagination"', $html);
        self::assertStringContainsString('<span ui-pagination="page" aria-current="page">2</span>', $html);
        // Other pages are links with the template expanded.
        self::assertStringContainsString('<a href="/list?page=1" ui-pagination="page">1</a>', $html);
        self::assertStringContainsString('<a href="/list?page=3" ui-pagination="page">3</a>', $html);
    }

    #[Test]
    public function prev_is_disabled_on_first_page_and_next_links_forward(): void
    {
        $html = $this->render(['current' => 1, 'total' => 5, 'hrefTemplate' => '/p/{page}']);

        self::assertStringContainsString('<span ui-pagination="prev" aria-disabled="true">', $html);
        self::assertStringContainsString('href="/p/2" ui-pagination="next"', $html);
    }

    #[Test]
    public function next_is_disabled_on_last_page(): void
    {
        $html = $this->render(['current' => 5, 'total' => 5, 'hrefTemplate' => '/p/{page}']);

        self::assertStringContainsString('<span ui-pagination="next" aria-disabled="true">', $html);
        self::assertStringContainsString('href="/p/4" ui-pagination="prev"', $html);
    }

    #[Test]
    public function large_range_windows_with_ellipsis_gaps(): void
    {
        // current=10 of 20, window=1 => visible: 1, …, 9,10,11, …, 20
        $html = $this->render(['current' => 10, 'total' => 20, 'hrefTemplate' => '/p/{page}']);

        self::assertStringContainsString('>1</a>', $html);
        self::assertStringContainsString('>20</a>', $html);
        self::assertStringContainsString('aria-current="page">10</span>', $html);
        self::assertStringContainsString('ui-pagination="gap"', $html);
        // Page 5 is outside the window and not first/last => absent.
        self::assertStringNotContainsString('>5</a>', $html);
        // Two gaps expected (before and after the window).
        self::assertSame(2, substr_count($html, 'ui-pagination="gap"'));
    }

    #[Test]
    public function single_page_has_both_controls_disabled_and_no_gaps(): void
    {
        $html = $this->render(['current' => 1, 'total' => 1, 'hrefTemplate' => '/p/{page}']);

        self::assertStringContainsString('<span ui-pagination="prev" aria-disabled="true">', $html);
        self::assertStringContainsString('<span ui-pagination="next" aria-disabled="true">', $html);
        self::assertStringNotContainsString('ui-pagination="gap"', $html);
        self::assertStringContainsString('aria-current="page">1</span>', $html);
    }

    #[Test]
    public function summary_slot_renders_before_the_controls(): void
    {
        $html = $this->render(
            ['current' => 1, 'total' => 3, 'hrefTemplate' => '/p/{page}'],
            ['summary' => '<span data-test="sum">1-10 of 30</span>'],
        );
        $sum = strpos($html, 'data-test="sum"');
        $pages = strpos($html, 'ui-pagination="pages"');
        self::assertNotFalse($sum);
        self::assertNotFalse($pages);
        self::assertLessThan($pages, $sum, 'summary renders before the page controls');
    }
}
