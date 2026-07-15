<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Component\Builtin\ListComponent;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\TwigFunction;

final class ListComponentRenderTest extends TestCase
{
    private TwigEnvironment $twig;

    protected function setUp(): void
    {
        UiComponentRegistry::reset();
        UiComponentRegistry::register(
            (new UiComponentMetadataFactory())->fromClass(ListComponent::class),
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
            '@platform-ui/components/runtime/list.html.twig',
            array_merge($props, [
                '_component' => ['name' => 'platform.list', 'class' => ListComponent::class],
                '_slots' => $slots,
            ]),
        );
    }

    #[Test]
    public function registers_header_and_empty_slots(): void
    {
        $meta = UiComponentRegistry::get('platform.list');
        self::assertNotNull($meta);
        $slots = array_keys($meta->slots);
        sort($slots);
        self::assertSame(['empty', 'header'], $slots);
    }

    #[Test]
    public function renders_items_with_optional_link_meta_and_description(): void
    {
        $html = $this->render(['items' => [
            ['title' => 'First', 'description' => 'the first one', 'meta' => '2m ago', 'href' => '/1'],
            ['title' => 'Second'],
        ]]);

        self::assertStringContainsString('data-ui-component="platform.list"', $html);
        self::assertStringContainsString('<a ui-list="title" href="/1">First</a>', $html);
        self::assertStringContainsString('<span ui-list="meta">2m ago</span>', $html);
        self::assertStringContainsString('<span ui-list="description">the first one</span>', $html);
        self::assertStringContainsString('<span ui-list="title">Second</span>', $html);
    }

    #[Test]
    public function empty_slot_shows_when_no_items(): void
    {
        $html = $this->render(['items' => []], ['empty' => '<em data-test="none">Empty</em>']);
        self::assertStringContainsString('<div ui-list="empty">', $html);
        self::assertStringContainsString('data-test="none"', $html);
        self::assertStringNotContainsString('ui-list="items"', $html);
    }

    #[Test]
    public function header_slot_renders_above_the_list(): void
    {
        $html = $this->render(
            ['items' => [['title' => 'X']]],
            ['header' => '<h3 data-test="h">Recent</h3>'],
        );
        $h = strpos($html, 'data-test="h"');
        $items = strpos($html, 'ui-list="items"');
        self::assertNotFalse($h);
        self::assertNotFalse($items);
        self::assertLessThan($items, $h);
    }
}
