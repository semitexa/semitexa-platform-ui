<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Semitexa\PlatformUi\Application\Component\Builtin\Block\CtaBlockComponent;
use Semitexa\PlatformUi\Application\Component\Builtin\Block\FaqBlockComponent;
use Semitexa\PlatformUi\Application\Component\Builtin\Block\FeaturesBlockComponent;
use Semitexa\PlatformUi\Application\Component\Builtin\Block\FooterBlockComponent;
use Semitexa\PlatformUi\Application\Component\Builtin\Block\HeroBlockComponent;
use Semitexa\PlatformUi\Application\Component\Builtin\Block\PricingBlockComponent;
use Semitexa\PlatformUi\Application\Component\Builtin\Block\StatsBlockComponent;
use Semitexa\PlatformUi\Application\Service\Icon\IconRegistry;
use Semitexa\PlatformUi\Application\Service\Link\UiSafeHref;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\BadgePrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\ButtonPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\PrimitiveRenderer;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveRegistry;
use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore;
use Semitexa\Ssr\Attribute\AsComponent;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * tk-br-blocks: every page block's contract examples are valid and render;
 * the blocks keep their semantics; a script URL in the data renders no link.
 */
final class PageBlocksRenderTest extends TestCase
{
    private const BLOCKS = [
        HeroBlockComponent::class, FeaturesBlockComponent::class, StatsBlockComponent::class,
        PricingBlockComponent::class, CtaBlockComponent::class, FaqBlockComponent::class, FooterBlockComponent::class,
    ];

    private TwigEnvironment $twig;

    protected function setUp(): void
    {
        UiPrimitiveRegistry::reset();
        AssetCollectorStore::reset();
        $factory = new UiPrimitiveMetadataFactory();
        UiPrimitiveRegistry::register($factory->fromClass(ButtonPrimitive::class));
        UiPrimitiveRegistry::register($factory->fromClass(BadgePrimitive::class));

        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2) . '/resources/twig', 'platform-ui');
        $this->twig = new TwigEnvironment($loader, ['cache' => false, 'autoescape' => 'html', 'strict_variables' => false]);
        $html = ['is_safe' => ['html']];
        $this->twig->addFunction(new TwigFunction('icon', static fn (string $n, array $o = []): Markup => new Markup(IconRegistry::render($n, $o), 'UTF-8'), $html));
        $this->twig->addFunction(new TwigFunction('ui_href', static fn (mixed $h): string => UiSafeHref::filter($h)));
        $this->twig->addFunction(new TwigFunction('asset_require', static fn (): string => ''));
        $this->twig->addFunction(new TwigFunction('slot', static fn (array $ctx, string $name): string => (string) ($ctx['_slots'][$name] ?? ''), ['needs_context' => true]));
        $renderer = new PrimitiveRenderer($this->twig);
        $this->twig->addFunction(new TwigFunction('primitive', static fn (string $n, array $p = []): Markup => new Markup($renderer->render($n, $p), 'UTF-8'), $html));
    }

    protected function tearDown(): void
    {
        UiPrimitiveRegistry::reset();
        AssetCollectorStore::reset();
    }

    /** @param class-string $class @param array<string, mixed> $props @param array<string, string> $slots */
    private function render(string $class, array $props, array $slots = []): string
    {
        $component = (new ReflectionClass($class))->getAttributes(AsComponent::class)[0]->newInstance();

        return $this->twig->render($component->template, $props + ['_slots' => $slots]);
    }

    #[Test]
    public function every_block_declares_a_contract_whose_examples_render(): void
    {
        foreach (self::BLOCKS as $class) {
            $attrs = (new ReflectionClass($class))->getAttributes(AsUiContract::class);
            self::assertCount(1, $attrs, $class);
            $contract = $attrs[0]->newInstance()->metadata(); // validates every example against the props
            self::assertNotSame([], $contract->examples, $class);
            foreach ($contract->examples as $example) {
                $html = $this->render($class, $example->props + $contract->defaults(), $example->slots);
                self::assertMatchesRegularExpression('/data-ui-component="platform\.block-[a-z]+"/', $html, $class . ' / ' . $example->name);
            }
        }
    }

    #[Test]
    public function the_hero_heading_level_and_media_slot(): void
    {
        $html = $this->render(HeroBlockComponent::class, ['title' => 'Hi', 'headingLevel' => 2], ['media' => '<img src="/a.png" alt="">']);
        self::assertStringContainsString('<h2 ui-hero-title>Hi</h2>', $html);
        self::assertStringContainsString(' ui-has-media', $html);
        self::assertStringContainsString('<div ui-hero-media><img src="/a.png" alt=""></div>', $html);

        $plain = $this->render(HeroBlockComponent::class, ['title' => 'Hi']);
        self::assertStringContainsString('<h1 ui-hero-title>Hi</h1>', $plain);
        self::assertStringNotContainsString('ui-has-media', $plain);
        self::assertStringNotContainsString('ui-block-actions', $plain);
    }

    #[Test]
    public function a_script_url_in_the_data_renders_no_link(): void
    {
        $hero = $this->render(HeroBlockComponent::class, ['title' => 'x', 'actions' => [
            ['label' => 'Bad', 'href' => 'javascript:alert(1)'], ['label' => 'Good', 'href' => '/ok'],
        ]]);
        self::assertStringNotContainsString('javascript', $hero);
        self::assertStringNotContainsString('Bad', $hero);
        self::assertStringContainsString('href="/ok"', $hero);

        $features = $this->render(FeaturesBlockComponent::class, ['items' => [['title' => 'T', 'href' => 'data:text/html,x']]]);
        self::assertStringContainsString('<h3 ui-feature-title>T</h3>', $features);

        $footer = $this->render(FooterBlockComponent::class, ['columns' => [['title' => 'C', 'links' => [['label' => 'L', 'href' => '//evil.test'], ['label' => 'Ok', 'href' => '/ok']]]]]);
        self::assertStringContainsString('<nav ui-footer-column aria-label="C">', $footer, 'the column is drawn');
        self::assertSame(1, substr_count($footer, '<li>'), 'only the safe link is listed');
        self::assertStringContainsString('<li><a href="/ok">Ok</a></li>', $footer);
        self::assertStringNotContainsString('evil', $footer);
    }

    #[Test]
    public function the_faq_is_native_details_sharing_a_name_unless_not_exclusive(): void
    {
        $items = [['question' => 'Q1', 'answer' => "A <b>1</b>\n\nsecond"], ['question' => 'Q2', 'answer' => 'A2']];
        $html = $this->render(FaqBlockComponent::class, ['items' => $items, 'group' => 'help', 'open' => 2]);
        self::assertSame(2, substr_count($html, '<details ui-faq-item name="help"'));
        self::assertSame(1, substr_count($html, ' open>'));
        self::assertStringContainsString('<p>A &lt;b&gt;1&lt;/b&gt;</p><p>second</p>', $html);

        $loose = $this->render(FaqBlockComponent::class, ['items' => $items, 'exclusive' => false]);
        self::assertSame(2, substr_count($loose, '<details ui-faq-item>'), 'both items drawn, neither in a group');
        self::assertStringNotContainsString('name=', $loose);
    }

    #[Test]
    public function stats_pair_each_number_with_its_label(): void
    {
        $html = $this->render(StatsBlockComponent::class, ['items' => [['value' => '12k', 'label' => 'users', 'caption' => 'monthly']]]);
        self::assertMatchesRegularExpression('#<dt ui-stats-label>users</dt>\s*<dd ui-stats-value>12k</dd><dd ui-stats-caption>monthly</dd>#', $html);
    }

    #[Test]
    public function the_featured_plan_gets_the_solid_action_and_others_outline(): void
    {
        $html = $this->render(PricingBlockComponent::class, ['plans' => [
            ['name' => 'A', 'price' => '$1', 'action' => ['label' => 'Pick A', 'href' => '#a']],
            ['name' => 'B', 'price' => '$2', 'featured' => true, 'badge' => 'Best', 'action' => ['label' => 'Pick B', 'href' => '#b']],
        ]]);
        self::assertMatchesRegularExpression('#href="\#a"[^>]*ui-variant="outline"#', $html);
        self::assertMatchesRegularExpression('#href="\#b"[^>]*ui-variant="solid"#', $html);
        self::assertSame(1, substr_count($html, ' ui-featured'));
        self::assertStringContainsString('Best', $html);
    }
}
