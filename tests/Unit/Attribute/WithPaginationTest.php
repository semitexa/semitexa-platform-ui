<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Attribute;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Attribute\WithPagination;

final class WithPaginationTest extends TestCase
{
    #[Test]
    public function defaults_keep_cursor_mode_for_backward_compat(): void
    {
        $attr = new WithPagination();

        self::assertSame(25, $attr->defaultLimit);
        self::assertSame([10, 25, 50, 100], $attr->limitOptions);
        self::assertSame(WithPagination::MODE_CURSOR, $attr->mode);
        self::assertSame(5, $attr->windowSize);
        self::assertSame(1000, $attr->autoCountThreshold);
    }

    #[Test]
    public function two_argument_form_still_defaults_to_cursor(): void
    {
        // The pre-existing call shape must keep working unchanged.
        $attr = new WithPagination(defaultLimit: 25, limitOptions: [10, 25, 50]);

        self::assertSame(WithPagination::MODE_CURSOR, $attr->mode);
        self::assertSame(5, $attr->windowSize);
    }

    #[Test]
    public function accepts_all_valid_modes(): void
    {
        foreach (WithPagination::VALID_MODES as $mode) {
            $attr = new WithPagination(mode: $mode);
            self::assertSame($mode, $attr->mode);
        }
    }

    #[Test]
    public function carries_new_fields(): void
    {
        $attr = new WithPagination(
            defaultLimit: 50,
            limitOptions: [25, 50, 100],
            mode: WithPagination::MODE_AUTO,
            windowSize: 7,
            autoCountThreshold: 5000,
        );

        self::assertSame(WithPagination::MODE_AUTO, $attr->mode);
        self::assertSame(7, $attr->windowSize);
        self::assertSame(5000, $attr->autoCountThreshold);
    }

    #[Test]
    public function rejects_unknown_mode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/mode "paged" is invalid/');

        new WithPagination(mode: 'paged');
    }

    #[Test]
    public function rejects_window_size_below_three(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/windowSize must be >= 3/');

        new WithPagination(windowSize: 1);
    }

    #[Test]
    public function rejects_even_window_size(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/windowSize must be odd/');

        new WithPagination(windowSize: 4);
    }

    #[Test]
    public function rejects_negative_auto_count_threshold(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/autoCountThreshold must be >= 0/');

        new WithPagination(autoCountThreshold: -1);
    }

    #[Test]
    public function zero_auto_count_threshold_is_allowed(): void
    {
        $attr = new WithPagination(autoCountThreshold: 0);

        self::assertSame(0, $attr->autoCountThreshold);
    }
}
