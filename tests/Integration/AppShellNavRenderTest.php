<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * The app shell marks the current navigation item and opens its group. A
 * prefix item matches its own path and the paths under it at a "/" — never a
 * longer sibling that only starts with the same letters.
 */
final class AppShellNavRenderTest extends TestCase
{
    private const NAV = [
        ['label' => 'Writing', 'items' => [['label' => 'Article', 'href' => '/admin/article', 'match' => 'prefix']]],
        ['label' => 'Library', 'items' => [['label' => 'Articles', 'href' => '/admin/articles', 'match' => 'prefix']]],
    ];

    private function render(string $path): string
    {
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2) . '/resources/twig', 'platform-ui');
        $twig = new TwigEnvironment($loader, ['cache' => false, 'autoescape' => 'html', 'strict_variables' => false]);
        $twig->addFunction(new TwigFunction('current_url', static fn (): string => $path . '?x=1'));
        $twig->addFunction(new TwigFunction('can', static fn (?string $p): bool => true));
        // Everything else the layout calls (assets, slots, icons) draws nothing here.
        $twig->registerUndefinedFunctionCallback(static fn (string $name): TwigFunction => new TwigFunction($name, static fn (): string => '', ['is_safe' => ['html']]));

        return $twig->render('@platform-ui/layouts/app-shell.html.twig', ['appNav' => self::NAV]);
    }

    /** @return list<string> the labels of the links marked current */
    private static function current(string $html): array
    {
        preg_match_all('#aria-current="page" title="([^"]+)"#', $html, $m);

        return $m[1];
    }

    /** @return list<string> the labels of the groups drawn open */
    private static function open(string $html): array
    {
        preg_match_all('#<details name="app-nav" ui-app-nav-group open>\s*<summary>([^<]+)</summary>#', $html, $m);

        return $m[1];
    }

    #[Test]
    public function a_prefix_item_is_not_current_on_a_longer_sibling_path(): void
    {
        $html = $this->render('/admin/articles');

        self::assertSame(['Articles'], self::current($html));
        self::assertSame(['Library'], self::open($html));
    }

    #[Test]
    public function a_prefix_item_is_current_on_its_own_path_and_below_it(): void
    {
        self::assertSame(['Article'], self::current($this->render('/admin/article')));
        $below = $this->render('/admin/article/42');
        self::assertSame(['Article'], self::current($below));
        self::assertSame(['Writing'], self::open($below));
    }
}
