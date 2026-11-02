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

    private static function compiler(): BundleCompiler
    {
        return new BundleCompiler(SliceCatalog::withDefaults(), new PrimitiveRegistry(), dirname(__DIR__, 3) . '/resources');
    }

    #[Test]
    public function an_empty_page_gets_the_baseline_and_tokens_only(): void
    {
        $bundle = self::compiler()->compile(new SliceRegistry());

        self::assertSame([], $bundle->sliceIds);
        self::assertSame([], $bundle->primitiveIds);
        self::assertStringContainsString('@layer platform-ui.reset', $bundle->css);
        self::assertStringContainsString('--ui-accent-brand', $bundle->css);
    }

    #[Test]
    public function a_realistic_page_stays_within_the_budget_and_balanced(): void
    {
        $catalog = SliceCatalog::withDefaults();
        $primitives = new PrimitiveRegistry();
        $page = (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Css/primitives-page.html');
        $registry = new SliceRegistry();
        (new TwigExtractor($catalog, $primitives))->extract($page)->applyTo($registry);

        $bundle = self::compiler()->compile($registry);

        self::assertNotSame([], $bundle->primitiveIds, 'the page uses primitives: the fixture is read');
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
    }

    #[Test]
    public function a_global_safelist_adds_to_every_page(): void
    {
        $safelist = new Safelist();
        $safelist->addGlobalPrimitive('badge');
        $safelist->addGlobalSlice('sx-tone:warning');
        $registry = new SliceRegistry();
        $safelist->apply($registry);

        self::assertContains('badge', $registry->primitiveIds());
        self::assertContains('sx-tone:warning', $registry->grammarSliceIds());
    }

    #[Test]
    public function a_route_safelist_adds_only_on_its_route(): void
    {
        $safelist = new Safelist();
        $safelist->addRoutePrimitive('/dashboard', 'badge');
        $registry = new SliceRegistry();

        $safelist->apply($registry, '/other');
        self::assertNotContains('badge', $registry->primitiveIds());
        $safelist->apply($registry, '/dashboard');
        self::assertContains('badge', $registry->primitiveIds());
    }
}
