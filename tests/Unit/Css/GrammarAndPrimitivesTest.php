<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Css;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Css\PrimitiveRegistry;
use Semitexa\PlatformUi\Application\Service\Css\Slice\SliceCatalog;
use Semitexa\PlatformUi\Application\Service\ValueValidator;

/**
 * The sx/ui grammar and the primitives as the bundle ships them. Was
 * tests/grammar-smoke.php and tests/primitives-smoke.php, scripts nothing ran:
 * the second had failed for a while unseen, on counts the registry outgrew
 * (6 primitives, 3 button variants) and on a {% macro %} the include-style
 * primitives (divider, tag, kbd…) never had. What holds is stated here
 * instead of counted: every primitive ships its rule and a template, and its
 * CSS styles no modifier the registry does not declare.
 */
final class GrammarAndPrimitivesTest extends TestCase
{
    #[Test]
    public function the_grammar_has_its_nine_attributes(): void
    {
        $attributes = SliceCatalog::withDefaults()->attributes();
        sort($attributes);

        self::assertSame(['sx-align', 'sx-gap', 'sx-justify', 'sx-layout', 'sx-padding', 'sx-radius', 'sx-surface', 'sx-tone', 'ui-text'], $attributes);
    }

    #[Test]
    public function every_slice_is_one_balanced_rule(): void
    {
        $slices = iterator_to_array(SliceCatalog::withDefaults()->emitAll(), false);
        self::assertNotEmpty($slices);
        foreach ($slices as $slice) {
            self::assertStringContainsString('{', $slice->css, $slice->id);
            self::assertSame(substr_count($slice->css, '{'), substr_count($slice->css, '}'), $slice->id);
        }
    }

    #[Test]
    public function a_value_is_valid_only_on_its_scale_and_a_refusal_names_the_scale(): void
    {
        $validator = new ValueValidator(SliceCatalog::withDefaults());

        self::assertTrue($validator->isValid('sx-gap', '4'));
        self::assertFalse($validator->isValid('sx-gap', '5'));
        self::assertFalse($validator->isValid('sx-bogus', 'x'));
        try {
            $validator->assert('sx-radius', 'xxl');
            self::fail('sx-radius=xxl was accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('none, sm, md, lg, pill', $e->getMessage());
        }
    }

    #[Test]
    public function every_primitive_ships_its_rule_in_the_primitives_layer_and_a_template(): void
    {
        foreach ((new PrimitiveRegistry())->all() as $primitive) {
            self::assertFileExists($primitive->cssPath);
            self::assertFileExists($primitive->twigPath);
            $css = (string) file_get_contents($primitive->cssPath);
            // A family styles its members by their own names: choice is
            // checkbox, radio and switch.
            self::assertMatchesRegularExpression('/\[ui="[a-z-]+"\]/', $css, $primitive->id . ' styles no [ui]');
            self::assertStringContainsString('@layer platform-ui.primitives', $css, $primitive->id);
            self::assertSame(substr_count($css, '{'), substr_count($css, '}'), $primitive->id . ': unbalanced braces');
        }
    }

    /**
     * A styled ui-variant/ui-tone/ui-size the registry does not declare is one
     * no extractor, validator or catalog knows about. The other way round is
     * not a rule: a declared value may be the base rule itself (alert's soft,
     * button's brand and md), styled by no attribute selector.
     */
    #[Test]
    public function a_primitive_styles_no_modifier_it_does_not_declare(): void
    {
        foreach ((new PrimitiveRegistry())->all() as $primitive) {
            $css = (string) file_get_contents($primitive->cssPath);
            foreach (['variant' => $primitive->variants, 'tone' => $primitive->tones, 'size' => $primitive->sizes] as $modifier => $declared) {
                preg_match_all('/ui-' . $modifier . '="([a-z0-9-]+)"/', $css, $m);
                $styled = array_values(array_unique($m[1]));
                self::assertSame([], array_values(array_diff($styled, $declared)), $primitive->id . ' styles an undeclared ui-' . $modifier);
            }
        }
    }
}
