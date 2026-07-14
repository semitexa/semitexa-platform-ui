<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Icon;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Icon\IconRegistry;

final class IconRegistryTest extends TestCase
{
    #[Test]
    public function ships_a_curated_set_and_resolves_by_name(): void
    {
        self::assertTrue(IconRegistry::has('menu'));
        self::assertTrue(IconRegistry::has('chevron-down'));
        self::assertFalse(IconRegistry::has('no-such-icon'));
        self::assertGreaterThanOrEqual(20, count(IconRegistry::names()));
        // the $note metadata key must not leak in as an icon
        self::assertNotContains('$note', IconRegistry::names());
    }

    #[Test]
    public function renders_inline_currentcolor_svg_decorative_by_default(): void
    {
        $svg = IconRegistry::render('menu');

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringContainsString('viewBox="0 0 24 24"', $svg);
        self::assertStringContainsString('stroke="currentColor"', $svg);
        self::assertStringContainsString('class="sx-icon"', $svg);
        self::assertStringContainsString('aria-hidden="true"', $svg);
        self::assertStringContainsString('width="24"', $svg);
        self::assertStringContainsString('<line', $svg); // the menu bars
    }

    #[Test]
    public function label_makes_it_an_accessible_image_and_size_class_apply(): void
    {
        $svg = IconRegistry::render('search', ['label' => 'Search', 'size' => 16, 'class' => 'text-muted']);

        self::assertStringContainsString('role="img"', $svg);
        self::assertStringContainsString('aria-label="Search"', $svg);
        self::assertStringNotContainsString('aria-hidden', $svg);
        self::assertStringContainsString('width="16"', $svg);
        self::assertStringContainsString('class="sx-icon text-muted"', $svg);
    }

    #[Test]
    public function unknown_icon_renders_nothing(): void
    {
        self::assertSame('', IconRegistry::render('definitely-not-an-icon'));
    }

    #[Test]
    public function add_registers_a_runtime_icon_that_wins(): void
    {
        IconRegistry::add('smoke-test-icon', '<circle cx="12" cy="12" r="9"/>');
        self::assertTrue(IconRegistry::has('smoke-test-icon'));
        self::assertStringContainsString('<circle cx="12"', IconRegistry::render('smoke-test-icon'));
    }
}
