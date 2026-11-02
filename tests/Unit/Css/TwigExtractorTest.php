<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Css;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Css\Extractor\TwigExtractor;
use Semitexa\PlatformUi\Application\Service\Css\PrimitiveRegistry;
use Semitexa\PlatformUi\Application\Service\Css\Slice\SliceCatalog;

/**
 * What a template asks of the CSS bundle: the grammar pairs and primitives it
 * uses, and what it names that the bundle cannot answer. Was the first half of
 * tests/bundle-smoke.php, a script nothing ran.
 */
final class TwigExtractorTest extends TestCase
{
    private static function extractor(): TwigExtractor
    {
        return new TwigExtractor(SliceCatalog::withDefaults(), new PrimitiveRegistry());
    }

    #[Test]
    public function a_template_yields_its_grammar_pairs_and_primitives_not_their_modifiers(): void
    {
        $m = self::extractor()->extract('<div sx-layout="stack" sx-gap="4"><button ui="button" ui-tone="brand" ui-variant="solid">Go</button></div>');

        $pairs = array_map(static fn (array $p): string => $p['attr'] . '=' . $p['value'], $m->attrs);
        sort($pairs);
        self::assertSame(['sx-gap=4', 'sx-layout=stack'], $pairs);
        self::assertSame(['button'], $m->primitives);
        self::assertSame([], $m->unresolved);
    }

    #[Test]
    public function a_dynamic_value_is_unresolved_not_guessed(): void
    {
        $m = self::extractor()->extract('<button ui="button" ui-tone="{{ toneVar }}">x</button>');

        self::assertSame([], $m->attrs);
        self::assertSame(['button'], $m->primitives);
        self::assertSame(['ui-tone="{{ toneVar }}"'], $m->unresolved);
    }

    #[Test]
    public function a_value_outside_the_scale_is_unresolved(): void
    {
        $m = self::extractor()->extract('<div sx-gap="5">x</div>');

        self::assertSame([], $m->attrs);
        self::assertSame(['sx-gap="5" (not in vocabulary)'], $m->unresolved);
    }

    #[Test]
    public function an_unknown_primitive_is_unresolved(): void
    {
        $m = self::extractor()->extract('<div ui="unknown-thing">x</div>');

        self::assertSame([], $m->primitives);
        self::assertSame(['ui="unknown-thing"'], $m->unresolved);
    }

    #[Test]
    public function a_repeated_pair_is_one(): void
    {
        self::assertCount(1, self::extractor()->extract('<a sx-gap="4"></a><b sx-gap="4"></b><c sx-gap="4"></c>')->attrs);
    }
}
