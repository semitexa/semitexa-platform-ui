<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Css;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The stacking order is a contract, not a habit.
 *
 * MEASURED before the scale existed: 25 z-index declarations across three
 * packages carrying 13 distinct values, with 60 used four times, 57 and 40
 * twice each, and one 9999. Nothing recorded which layer was meant to win, so
 * every new overlay was a guess against the last one.
 */
final class ZLayerScaleTest extends TestCase
{
    /** Declared low-to-high. A later entry sitting below an earlier one is the bug. */
    private const ORDER = [
        '--ui-z-base',
        '--ui-z-dropdown',
        '--ui-z-backdrop',
        '--ui-z-offcanvas',
        '--ui-z-tooltip',
        '--ui-z-toast',
    ];

    #[Test]
    public function the_page_layers_rise_in_the_order_they_are_declared(): void
    {
        $values = $this->tokens();

        $previous = null;
        foreach (self::ORDER as $token) {
            self::assertArrayHasKey($token, $values, $token . ' is missing from the scale');
            if ($previous !== null) {
                self::assertGreaterThan(
                    $values[$previous],
                    $values[$token],
                    $token . ' must sit above ' . $previous,
                );
            }
            $previous = $token;
        }
    }

    /**
     * A bare number is a decision made without the scale, and it is how the two
     * numeric universes grew in the first place.
     */
    #[Test]
    public function no_stylesheet_carries_a_bare_z_index(): void
    {
        $offenders = [];

        foreach ($this->stylesheets() as $file) {
            $css = (string) file_get_contents($file);
            if (preg_match_all('/z-index:\s*-?\d/', $css, $m) > 0) {
                $offenders[] = basename($file) . ' (' . count($m[0]) . ')';
            }
        }

        sort($offenders);

        self::assertSame(
            [],
            $offenders,
            "These stylesheets set a z-index without the scale:\n  - " . implode("\n  - ", $offenders),
        );
    }

    /**
     * baseline.css and full.css are BUILD OUTPUT. The scale was first written
     * straight into baseline.css, and the next `platform-ui:css:build` erased
     * it without a word — the file simply stopped being modified. This asserts
     * the tokens are in the compiled bundles, which is only true when they come
     * from resources/baseline/.
     */
    #[Test]
    public function the_scale_survives_a_rebuild(): void
    {
        foreach (['baseline.css', 'full.css'] as $bundle) {
            $path = $this->cssDir() . '/' . $bundle;
            self::assertFileExists($path);
            self::assertStringContainsString(
                '--ui-z-toast',
                (string) file_get_contents($path),
                $bundle . ' is generated; the scale must come from resources/baseline/ to survive the build',
            );
        }
    }

    /** @return array<string, int> */
    private function tokens(): array
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/resources/baseline/z-layers.css',
        );

        preg_match_all('/(--ui-z-[a-z-]+):\s*(\d+)\s*;/', $source, $matches, PREG_SET_ORDER);

        $values = [];
        foreach ($matches as $match) {
            $values[$match[1]] = (int) $match[2];
        }

        return $values;
    }

    /** @return list<string> authored stylesheets, never build output */
    private function stylesheets(): array
    {
        $files = glob($this->cssDir() . '/*.css') ?: [];

        return array_values(array_filter(
            $files,
            static fn (string $file): bool => !in_array(basename($file), ['baseline.css', 'full.css'], true),
        ));
    }

    private function cssDir(): string
    {
        return dirname(__DIR__, 3) . '/src/Application/Static/css';
    }
}
