<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Component\Builtin\StatComponent;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\TwigFunction;

final class StatComponentRenderTest extends TestCase
{
    private TwigEnvironment $twig;

    protected function setUp(): void
    {
        UiComponentRegistry::reset();
        UiComponentRegistry::register(
            (new UiComponentMetadataFactory())->fromClass(StatComponent::class),
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
            '@platform-ui/components/runtime/stat.html.twig',
            array_merge($props, [
                '_component' => ['name' => 'platform.stat', 'class' => StatComponent::class],
                '_slots' => $slots,
            ]),
        );
    }

    #[Test]
    public function registers_visual_slot(): void
    {
        $meta = UiComponentRegistry::get('platform.stat');
        self::assertNotNull($meta);
        self::assertSame(['visual'], array_keys($meta->slots));
    }

    #[Test]
    public function renders_label_value_and_default_flat_trend_delta(): void
    {
        $html = $this->render(['label' => 'Revenue', 'value' => '$12.4k', 'delta' => '+3%']);

        self::assertStringContainsString('data-ui-component="platform.stat"', $html);
        self::assertStringContainsString('<div ui-stat="label">Revenue</div>', $html);
        self::assertStringContainsString('<div ui-stat="value">$12.4k</div>', $html);
        self::assertStringContainsString('<div ui-stat="delta" ui-trend="flat">+3%</div>', $html);
    }

    #[Test]
    public function trend_prop_drives_delta_direction(): void
    {
        $up = $this->render(['label' => 'X', 'value' => '9', 'delta' => '+1', 'trend' => 'up']);
        self::assertStringContainsString('ui-trend="up">+1</div>', $up);

        $down = $this->render(['label' => 'X', 'value' => '9', 'delta' => '-1', 'trend' => 'down']);
        self::assertStringContainsString('ui-trend="down">-1</div>', $down);
    }

    #[Test]
    public function delta_and_caption_are_omitted_when_absent(): void
    {
        $html = $this->render(['label' => 'Users', 'value' => '128']);
        self::assertStringNotContainsString('ui-stat="delta"', $html);
        self::assertStringNotContainsString('ui-stat="caption"', $html);
    }

    #[Test]
    public function visual_slot_renders_before_the_body(): void
    {
        $html = $this->render(
            ['label' => 'X', 'value' => '1'],
            ['visual' => '<svg data-test="v"></svg>'],
        );
        $v = strpos($html, 'data-test="v"');
        $body = strpos($html, 'ui-stat="body"');
        self::assertNotFalse($v);
        self::assertNotFalse($body);
        self::assertLessThan($body, $v);
    }
}
