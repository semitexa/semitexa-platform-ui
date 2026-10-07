<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Url;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Semitexa\PlatformUi\Application\Service\Url\UiUrlBindings;
use Semitexa\PlatformUi\Application\Service\Url\UiUrlPropsOverlay;
use Semitexa\PlatformUi\Attribute\UiUrl;
use Semitexa\PlatformUi\Domain\Model\Event\UiResponsePatch;
use Semitexa\Ssr\Application\Service\Component\ComponentPropsOverlays;
use Semitexa\Ssr\Attribute\AsComponent;

/** tk-la-url-state: URL state is attacker input on load, and follows the props after. */
final class UiUrlBindingsTest extends TestCase
{
    protected function setUp(): void
    {
        UiComponentRegistry::reset();
        UiComponentRegistry::register((new UiComponentMetadataFactory())->fromClass(UrlSearchFixture::class));
    }

    protected function tearDown(): void
    {
        UiComponentRegistry::reset();
        ComponentPropsOverlays::reset();
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>}> */
    public static function loads(): iterable
    {
        yield 'valid values win over the caller' => [['q' => 'swoole', 'page' => '3', 'sort' => 'new'], ['query' => 'swoole', 'page' => 3, 'sort' => 'new']];
        yield 'an array is not a value' => [['q' => ['x']], ['query' => '', 'page' => 1, 'sort' => 'top']];
        yield 'out of range' => [['page' => '0'], ['query' => '', 'page' => 1, 'sort' => 'top']];
        yield 'not an int' => [['page' => '2abc'], ['query' => '', 'page' => 1, 'sort' => 'top']];
        yield 'not on the allow-list' => [['sort' => 'random'], ['query' => '', 'page' => 1, 'sort' => 'top']];
        yield 'too long' => [['q' => str_repeat('a', 41)], ['query' => '', 'page' => 1, 'sort' => 'top']];
        yield 'unbound keys are ignored' => [['query' => 'x', 'admin' => '1'], ['query' => '', 'page' => 1, 'sort' => 'top']];
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('loads')]
    public function a_page_load_restores_only_valid_values(array $query, array $expected): void
    {
        self::assertSame($expected, UiUrlBindings::restore('url.search', ['query' => '', 'page' => 1, 'sort' => 'top'], $query));
    }

    #[Test]
    public function a_changed_prop_becomes_one_url_effect(): void
    {
        $effect = UiUrlBindings::effectFor('url.search', 'uci_url_test_00000001', ['query' => '', 'page' => 2], ['query' => 'php', 'page' => 1]);

        self::assertNotNull($effect);
        self::assertSame(UiResponsePatch::OP_URL, $effect->op);
        // page went back to its `except` value: dropped from the URL; page is a push binding.
        self::assertSame(['params' => ['q' => 'php', 'page' => null], 'history' => 'push'], $effect->args);
        self::assertNull(UiUrlBindings::effectFor('url.search', 'uci_url_test_00000001', ['query' => 'a'], ['query' => 'a']), 'nothing changed, nothing written');
    }

    #[Test]
    public function only_a_page_get_is_overlaid(): void
    {
        ComponentPropsOverlays::register(new UiUrlPropsOverlay());
        $props = ['query' => ''];

        self::assertSame(['query' => 'x'], ComponentPropsOverlays::apply('url.search', $props, new UrlFakeRequest('GET', '/blog', ['q' => 'x'])));
        self::assertSame($props, ComponentPropsOverlays::apply('url.search', $props, new UrlFakeRequest('POST', '/blog', ['q' => 'x'])), 'a re-render keeps its handler\'s props');
        self::assertSame($props, ComponentPropsOverlays::apply('url.search', $props, new UrlFakeRequest('GET', '/__semitexa_kiss', ['q' => 'x'])), 'transport doors are not pages');
        self::assertSame($props, ComponentPropsOverlays::apply('url.search', $props, null));
    }

    #[Test]
    public function a_bad_declaration_fails_loudly(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/history must be/');
        UiUrlBindings::forClass(UrlBadFixture::class);
    }
}

#[AsComponent(name: 'url.search', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiUrl(prop: 'query', as: 'q', maxLength: 40)]
#[UiUrl(prop: 'page', type: 'int', min: 1, except: 1, history: 'push')]
#[UiUrl(prop: 'sort', values: ['top', 'new'])]
final class UrlSearchFixture
{
}

#[UiUrl(prop: 'tab', history: 'sometimes')]
final class UrlBadFixture
{
}

final class UrlFakeRequest
{
    /** @param array<string, mixed> $query */
    public function __construct(private string $method, private string $path, public array $query) {}

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }
}
