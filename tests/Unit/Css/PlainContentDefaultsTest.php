<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Css;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Plain content on a base layout reads as part of the page
 * (bench-agent-skips-default-theme): an agent's hand-written table rendered
 * with the browser's defaults beside a themed page. The defaults sit in the
 * reset layer, so whatever a component sets wins by layer; they reach only
 * elements no component owns; and they leave buttons and fields alone, which
 * components rely on bare.
 */
final class PlainContentDefaultsTest extends TestCase
{
    private const BUILT = __DIR__ . '/../../../src/Application/Static/css/baseline.css';

    private const PLAIN = ['table', 'pre', 'hr', 'blockquote'];

    private const GUARD = ['[class]', '[ui]', '[role]', '[ui-table]', '[ui-component] *', '[data-ui-component] *', '[data-ui-primitive] *'];

    #[Test]
    public function every_plain_element_has_its_defaults_in_the_reset_layer(): void
    {
        $reset = implode("\n", self::layerBlocks((string) file_get_contents(self::BUILT), 'platform-ui.reset'));

        self::assertNotSame('', $reset, 'the built baseline has a platform-ui.reset layer');
        foreach (self::PLAIN as $element) {
            self::assertStringContainsString(":where(main {$element}:not(", $reset, "{$element} has its defaults in the reset layer");
        }
        self::assertStringContainsString('border-block-end: 1px solid var(--ui-border-subtle)', $reset, 'a plain table has rules between its rows');
    }

    #[Test]
    public function no_plain_content_rule_sits_outside_a_layer(): void
    {
        $css = (string) file_get_contents(self::BUILT);
        $blocks = self::layerBlocks($css, null);
        $outside = $css;
        foreach ($blocks as $block) {
            $outside = str_replace($block, '', $outside);
        }

        self::assertNotEmpty($blocks, 'the built baseline is made of layer blocks');
        // What stays outside is the layer order itself: proof the blocks were cut out and the rest was read.
        self::assertStringContainsString('@layer platform-ui.reset, platform-ui.tokens', $outside);
        // Unlayered, a rule would beat every layered component rule, whatever its selector.
        self::assertStringNotContainsString('main table', $outside);
        self::assertStringNotContainsString('main pre', $outside);
    }

    #[Test]
    public function every_rule_is_zero_specificity_and_keeps_out_of_components(): void
    {
        $rules = self::plainRules((string) file_get_contents(self::BUILT));

        self::assertCount(8, $rules, 'table, caption, cells, th, thead th, pre, hr, blockquote');
        foreach ($rules as $selector) {
            self::assertStringStartsWith(':where(', $selector, "zero specificity: {$selector}");
            foreach (self::GUARD as $exclusion) {
                self::assertStringContainsString($exclusion, $selector, "{$selector} keeps out of what {$exclusion} marks");
            }
        }
    }

    #[Test]
    public function buttons_and_fields_are_left_to_the_components(): void
    {
        $rules = self::plainRules((string) file_get_contents(self::BUILT));

        self::assertCount(8, $rules);
        foreach ($rules as $selector) {
            self::assertStringStartsWith(':where(main ', $selector);
            self::assertDoesNotMatchRegularExpression('/\b(button|input|select|textarea)\b/', $selector, 'the command palette and the workbench rely on the bare control');
        }
    }

    /**
     * The selectors of the rules this change added: the ones scoped to main.
     *
     * @return list<string>
     */
    private static function plainRules(string $css): array
    {
        preg_match_all('/(:where\(main [^{]+)\{/', $css, $m);

        return array_map('trim', $m[1]);
    }

    /**
     * The bodies of every `@layer <name> { … }` block (any layer when $name is null), by brace matching.
     *
     * @return list<string>
     */
    private static function layerBlocks(string $css, ?string $name): array
    {
        $pattern = $name === null ? '/@layer\s+[\w.-]+\s*\{/' : '/@layer\s+' . preg_quote($name, '/') . '\s*\{/';
        preg_match_all($pattern, $css, $m, PREG_OFFSET_CAPTURE);
        $blocks = [];
        foreach ($m[0] as [$open, $offset]) {
            $depth = 0;
            $length = strlen($css);
            for ($i = $offset + strlen($open) - 1; $i < $length; $i++) {
                $depth += $css[$i] === '{' ? 1 : ($css[$i] === '}' ? -1 : 0);
                if ($depth === 0) {
                    $blocks[] = substr($css, $offset, $i - $offset + 1);
                    break;
                }
            }
        }

        return $blocks;
    }
}
