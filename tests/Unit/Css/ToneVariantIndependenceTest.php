<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Css;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tone and variant are independent axes: a tone rule names a colour, a
 * variant rule decides how it is used, and no selector couples the two.
 *
 * MEASURED before this guard: the colour rules were written per pair and
 * required ui-variant="solid" to be spelled out, so `ui-tone="danger"` on its
 * own rendered the brand colour — a grey "danger" button and grey badges for
 * every status, in a kit whose default variant IS solid.
 */
final class ToneVariantIndependenceTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function primitives(): iterable
    {
        yield 'button' => ['button'];
        yield 'badge' => ['badge'];
        yield 'alert' => ['alert'];
    }

    #[Test]
    #[DataProvider('primitives')]
    public function no_selector_pairs_a_tone_with_a_variant(string $primitive): void
    {
        $css = $this->css($primitive);
        preg_match_all('/([^{}]+)\{/', $css, $m);
        $coupled = [];
        foreach ($m[1] as $selector) {
            if (preg_match('/\[ui-tone="[^"]+"\]/', $selector) === 1 && preg_match('/\[ui-variant="[^"]+"\]/', $selector) === 1) {
                $coupled[] = trim($selector);
            }
        }
        self::assertSame([], $coupled, 'A tone rule must not depend on the variant.');
    }

    #[Test]
    #[DataProvider('primitives')]
    public function every_declared_tone_sets_the_tone_colour(string $primitive): void
    {
        $css = $this->css($primitive);
        preg_match_all('/\[ui="' . $primitive . '"\]\[ui-tone="([a-z]+)"\]\s*\{[^}]*--_tone\s*:/', $css, $m);
        $tones = array_unique($m[1]);
        sort($tones);

        $expected = match ($primitive) {
            'button', 'badge' => ['danger', 'info', 'neutral', 'success', 'warning'],
            'alert' => ['danger', 'info', 'neutral', 'success', 'warning'],
        };
        foreach ($expected as $tone) {
            self::assertContains($tone, $tones, "ui-tone=\"{$tone}\" must set --_tone on [ui=\"{$primitive}\"].");
        }
    }

    private function css(string $primitive): string
    {
        $css = (string) file_get_contents(\dirname(__DIR__, 3) . '/resources/primitives/' . $primitive . '.css');
        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }
}
