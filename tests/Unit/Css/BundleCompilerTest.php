<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Css;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Asset\SliceRegistry;
use Semitexa\PlatformUi\Application\Service\Css\Compiler\BundleCompiler;
use Semitexa\PlatformUi\Application\Service\Css\Extractor\TwigExtractor;
use Semitexa\PlatformUi\Application\Service\Css\PrimitiveRegistry;
use Semitexa\PlatformUi\Application\Service\Css\Safelist;
use Semitexa\PlatformUi\Application\Service\Css\Slice\SliceCatalog;

/**
 * The per-page CSS bundle: baseline and tokens always, then only the slices
 * and primitives the page uses, under a hash that names exactly that set.
 * Was tests/bundle-smoke.php, a script nothing ran.
 */
final class BundleCompilerTest extends TestCase
{
    /** The M4 budget: a realistic page's bundle stays under 8 KiB gzipped. */
    private const GZIP_BUDGET = 8192;

    private const BASELINE = ['layers.css', 'z-layers.css', 'foundation.css', 'reset.css', 'typography.css'];

    private static function resources(): string
    {
        return dirname(__DIR__, 3) . '/resources';
    }

    private static function compiler(): BundleCompiler
    {
        return new BundleCompiler(SliceCatalog::withDefaults(), new PrimitiveRegistry(), self::resources());
    }

    private static function read(string $path): string
    {
        $css = file_get_contents($path);
        self::assertIsString($css, 'unreadable: ' . $path);
        self::assertNotSame('', trim($css), 'empty: ' . $path);

        return $css;
    }

    #[Test]
    public function an_empty_page_gets_the_baseline_and_tokens_only(): void
    {
        $bundle = self::compiler()->compile(new SliceRegistry());

        self::assertSame([], $bundle->sliceIds);
        self::assertSame([], $bundle->primitiveIds);
        // Each baseline file whole: a layer name or a token alone is also
        // spelled by a neighbouring file, and would pass with one missing.
        foreach (self::BASELINE as $file) {
            self::assertStringContainsString(trim(self::read(self::resources() . '/baseline/' . $file)), $bundle->css, $file);
        }
    }

    #[Test]
    public function a_realistic_page_stays_within_the_budget_and_balanced(): void
    {
        $catalog = SliceCatalog::withDefaults();
        $primitives = new PrimitiveRegistry();
        $page = self::read(dirname(__DIR__, 2) . '/Fixtures/Css/primitives-page.html');
        $registry = new SliceRegistry();
        (new TwigExtractor($catalog, $primitives))->extract($page)->applyTo($registry);

        $bundle = self::compiler()->compile($registry);

        self::assertNotSame([], $bundle->primitiveIds, 'the page uses primitives: the fixture is read');
        self::assertNotSame([], $bundle->sliceIds, 'the page uses grammar: the fixture is read');
        // An id is listed only for CSS the bundle carries: a primitive whose
        // file went missing would still be named.
        foreach ($bundle->primitiveIds as $id) {
            $primitive = $primitives->get($id);
            self::assertNotNull($primitive, $id);
            self::assertStringContainsString(trim(self::read($primitive->cssPath)), $bundle->css, $id);
        }
        self::assertStringContainsString('@layer platform-ui.grammar', $bundle->css);
        self::assertLessThan(self::GZIP_BUDGET, $bundle->gzipSize());
        self::assertSame(substr_count($bundle->css, '{'), substr_count($bundle->css, '}'));
    }

    #[Test]
    public function the_hash_names_the_set_it_was_compiled_from(): void
    {
        $stack = new SliceRegistry();
        $stack->registerGrammar('sx-layout', 'stack');
        $stack->registerPrimitive('button');
        $cluster = new SliceRegistry();
        $cluster->registerGrammar('sx-layout', 'cluster');

        self::assertSame(self::compiler()->compile($stack)->hash, self::compiler()->compile($stack)->hash);
        self::assertNotSame(self::compiler()->compile($stack)->hash, self::compiler()->compile($cluster)->hash);
        // Same ids, different CSS: the hash is of the content, not of the ids.
        self::assertNotSame(self::compiler()->compile($stack)->hash, self::compiler()->compile($stack, includeBaseline: false)->hash);
    }

    #[Test]
    public function a_global_safelist_adds_to_every_page(): void
    {
        $safelist = new Safelist();
        $safelist->addGlobalPrimitive('badge');
        $safelist->addGlobalSlice('sx-tone:warning');
        $registry = new SliceRegistry();
        $safelist->apply($registry);

        self::assertSame(['badge'], $registry->primitiveIds());
        self::assertSame(['sx-tone:warning'], $registry->grammarSliceIds());
    }

    #[Test]
    public function a_route_safelist_adds_only_on_its_route(): void
    {
        $safelist = new Safelist();
        $safelist->addRoutePrimitive('/dashboard', 'badge');
        $registry = new SliceRegistry();

        $safelist->apply($registry, '/other');
        self::assertSame([], $registry->primitiveIds());
        $safelist->apply($registry, '/dashboard');
        self::assertSame(['badge'], $registry->primitiveIds());
        self::assertSame([], $registry->grammarSliceIds());
    }
}
