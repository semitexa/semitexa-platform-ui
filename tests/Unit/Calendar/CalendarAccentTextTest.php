<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Calendar;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Text on the calendar's accent fill ("＋ New event", "＋ New on this day")
 * must take the skin's on-accent colour, which each skin picks per mode.
 *
 * MEASURED before this guard: the button hard-coded #04121f, a dark ink meant
 * for a light-blue accent; on the default skin's light-mode accent it read at
 * 3.41:1, under WCAG AA's 4.5:1 (UI Workbench E2E, platform.calendar/default).
 */
final class CalendarAccentTextTest extends TestCase
{
    #[Test]
    public function text_on_the_accent_fill_uses_the_on_accent_token(): void
    {
        $css = (string) file_get_contents(\dirname(__DIR__, 3) . '/src/Application/Static/css/calendar.css');
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);

        $filled = [];
        foreach ($rules as [, $selector, $body]) {
            // Any background declaration that uses the accent token itself, wherever
            // in the value (a gradient too), and not a longer token that only
            // starts with it (--ui-accent-brand-contrast).
            if (preg_match('/(?:^|;)\s*background(?:-color)?\s*:[^;]*var\(\s*--ui-accent-brand\s*[,)]/', $body) !== 1) {
                continue;
            }
            preg_match('/(?:^|;)\s*color\s*:\s*([^;]+)/', $body, $color);
            $filled[trim($selector)] = trim($color[1] ?? '');
        }

        self::assertSame(['.uical__btn--go' => 'var(--ui-text-on-accent)'], $filled);
    }
}
