<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Css;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Css\Slice\SliceCatalog;
use Semitexa\Theme\Application\Service\Skin\TokenContract;

/**
 * Every `var(--ui-*)` the kit reads must be defined somewhere a page actually
 * loads: the skin contract (semitexa/theme) or platform-ui's own foundation.
 *
 * MEASURED before this guard: behaviors.css asked for --ui-elevation-*,
 * --ui-space-*, --ui-overlay-scrim and --ui-surface-inverse that nothing
 * defined, fell back to 26 hard-coded colours, and no skin could restyle a
 * single overlay. A fallback in var() hides the hole; this test does not.
 */
final class TokenCoverageTest extends TestCase
{
    #[Test]
    public function every_referenced_ui_token_is_defined(): void
    {
        $defined = $this->definedTokens();
        $missing = [];

        foreach ($this->stylesheets() as $file => $css) {
            foreach ($this->referencedTokens($css) as $token) {
                if (!isset($defined[$token])) {
                    $missing[$token][] = $file;
                }
            }
        }

        foreach (SliceCatalog::withDefaults()->emitAll() as $slice) {
            foreach ($this->referencedTokens($slice->css) as $token) {
                if (!isset($defined[$token])) {
                    $missing[$token][] = 'grammar ' . $slice->id;
                }
            }
        }

        $report = [];
        foreach ($missing as $token => $files) {
            $report[] = $token . ' (' . implode(', ', array_unique($files)) . ')';
        }
        self::assertSame([], $report, "Tokens read but never defined:\n" . implode("\n", $report));
    }

    #[Test]
    public function component_and_behavior_styles_carry_no_literal_colours(): void
    {
        // Colour belongs to the skin. A literal here is a colour no skin can reach.
        // Exceptions are the fixed on-tone text colours for state fills, which
        // must stay readable whatever the skin does to the state hue.
        $allowed = ['#fff', '#1a1300', '#000'];
        $found = [];
        foreach (['components.css', 'behaviors.css'] as $name) {
            $css = $this->stripComments((string) file_get_contents($this->staticCss() . '/' . $name));
            preg_match_all('/#[0-9a-fA-F]{3,8}\b|\brgba?\(/', $css, $m);
            foreach ($m[0] as $literal) {
                if (!in_array(strtolower($literal), $allowed, true)) {
                    $found[] = $name . ': ' . $literal;
                }
            }
        }
        self::assertSame([], $found);
    }

    #[Test]
    public function colour_mixing_never_interpolates_hue(): void
    {
        // color-mix(in oklch) interpolates hue. White and greys have an
        // arbitrary hue there, so mixing a near-neutral surface with white
        // drifted card footers and table headers visibly pink. oklab has no hue
        // axis to drift along.
        $found = [];
        foreach ($this->stylesheets() as $file => $css) {
            if (preg_match('/color-mix\(\s*in\s+(oklch|lch|hsl|hwb)\b/i', $css, $m) === 1) {
                $found[] = $file . ': ' . $m[0];
            }
        }
        self::assertSame([], $found);
    }

    /** @return array<string, true> */
    private function definedTokens(): array
    {
        $defined = [];
        foreach (TokenContract::cases() as $case) {
            $defined[$case->value] = true;
        }
        foreach (['foundation.css', 'z-layers.css', 'typography.css'] as $file) {
            $css = (string) file_get_contents($this->resources() . '/baseline/' . $file);
            preg_match_all('/(--ui-[a-z0-9-]+)\s*:/', $css, $m);
            foreach ($m[1] as $name) {
                $defined[$name] = true;
            }
        }
        return $defined;
    }

    /** @return array<string, string> file => css */
    private function stylesheets(): array
    {
        $files = array_merge(
            glob($this->resources() . '/primitives/*.css') ?: [],
            glob($this->resources() . '/baseline/*.css') ?: [],
            glob($this->staticCss() . '/*.css') ?: [],
        );
        $out = [];
        foreach ($files as $file) {
            $out[basename(\dirname($file)) . '/' . basename($file)] = $this->stripComments((string) file_get_contents($file));
        }
        return $out;
    }

    /** @return list<string> */
    private function referencedTokens(string $css): array
    {
        preg_match_all('/var\(\s*(--ui-[a-z0-9-]+)/', $css, $m);
        return array_values(array_unique($m[1]));
    }

    private function stripComments(string $css): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }

    private function resources(): string
    {
        return \dirname(__DIR__, 3) . '/resources';
    }

    private function staticCss(): string
    {
        return \dirname(__DIR__, 3) . '/src/Application/Static/css';
    }
}
