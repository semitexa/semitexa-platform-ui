<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Component\Builtin\EmptyStateComponent;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Semitexa\PlatformUi\Application\Service\Icon\IconRegistry;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\TwigFunction;

final class EmptyStateComponentRenderTest extends TestCase
{
    private TwigEnvironment $twig;

    protected function setUp(): void
    {
        UiComponentRegistry::reset();
        UiComponentRegistry::register(
            (new UiComponentMetadataFactory())->fromClass(EmptyStateComponent::class),
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
        // empty-state's template renders the `icon` prop through icon().
        $this->twig->addFunction(new TwigFunction(
            'icon',
            static fn (string $name, array $opts = []): Markup => new Markup(IconRegistry::render($name, $opts), 'UTF-8'),
            ['is_safe' => ['html']],
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
            '@platform-ui/components/runtime/empty-state.html.twig',
            array_merge($props, [
                '_component' => ['name' => 'platform.empty-state', 'class' => EmptyStateComponent::class],
                '_slots' => $slots,
            ]),
        );
    }

    #[Test]
    public function registers_media_and_actions_slots(): void
    {
        $meta = UiComponentRegistry::get('platform.empty-state');
        self::assertNotNull($meta);
        $slots = array_keys($meta->slots);
        sort($slots);
        self::assertSame(['actions', 'media'], $slots);
    }

    #[Test]
    public function renders_icon_prop_title_and_description(): void
    {
        $html = $this->render([
            'icon' => 'search',
            'title' => 'No results',
            'description' => 'Try a different query.',
        ]);

        self::assertStringContainsString('data-ui-component="platform.empty-state"', $html);
        self::assertStringContainsString('<div ui-empty="media">', $html);
        self::assertStringContainsString('class="sx-icon"', $html); // icon() output
        self::assertStringContainsString('<div ui-empty="title">No results</div>', $html);
        self::assertStringContainsString('<div ui-empty="description">Try a different query.</div>', $html);
    }

    #[Test]
    public function media_slot_overrides_the_icon_prop(): void
    {
        $html = $this->render(
            ['icon' => 'search', 'title' => 'Empty'],
            ['media' => '<img data-test="illustration" src="/empty.svg" alt="">'],
        );

        self::assertStringContainsString('data-test="illustration"', $html);
        // The icon() SVG must NOT also render when a media slot is provided.
        self::assertStringNotContainsString('class="sx-icon"', $html);
    }

    #[Test]
    public function actions_slot_renders_after_the_text(): void
    {
        $html = $this->render(
            ['title' => 'Nothing yet'],
            ['actions' => '<button data-test="cta">Create</button>'],
        );

        $title = strpos($html, 'ui-empty="title"');
        $actions = strpos($html, 'data-test="cta"');
        self::assertNotFalse($title);
        self::assertNotFalse($actions);
        self::assertLessThan($actions, $title);
    }

    #[Test]
    public function no_icon_and_no_media_renders_no_media_block(): void
    {
        $html = $this->render(['title' => 'Bare']);
        self::assertStringNotContainsString('ui-empty="media"', $html);
    }
}
