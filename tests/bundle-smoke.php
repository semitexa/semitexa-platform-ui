<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

use Semitexa\PlatformUi\Application\Service\Asset\SliceRegistry;
use Semitexa\PlatformUi\Application\Service\Css\Compiler\BundleCompiler;
use Semitexa\PlatformUi\Application\Service\Css\Extractor\TwigExtractor;
use Semitexa\PlatformUi\Application\Service\Css\Safelist;
use Semitexa\PlatformUi\Application\Service\Css\Slice\SliceCatalog;
use Semitexa\PlatformUi\Application\Service\Css\PrimitiveRegistry;

$errors = [];
$ok = static function (string $label, callable $fn) use (&$errors): void {
    try {
        $fn();
        echo "  ok  {$label}\n";
    } catch (\Throwable $e) {
        $errors[] = "{$label}: {$e->getMessage()}";
        echo "  FAIL {$label}: {$e->getMessage()}\n";
    }
};

$catalog = SliceCatalog::withDefaults();
$primitives = new PrimitiveRegistry();
$extractor = new TwigExtractor($catalog, $primitives);
$compiler = new BundleCompiler($catalog, $primitives, __DIR__ . '/../resources');

echo "== TwigExtractor ==\n";

$ok('extracts grammar slices + primitive id, ignores primitive modifiers', static function () use ($extractor) {
    $html = '<div sx-layout="stack" sx-gap="4"><button ui="button" ui-tone="brand" ui-variant="solid">Go</button></div>';
    $m = $extractor->extract($html);
    $pairs = array_map(static fn($p) => "{$p['attr']}={$p['value']}", $m->attrs);
    sort($pairs);
    $expected = ['sx-gap=4', 'sx-layout=stack'];
    if ($pairs !== $expected) throw new \RuntimeException('got: ' . implode(',', $pairs));
    if ($m->primitives !== ['button']) throw new \RuntimeException('primitives: ' . implode(',', $m->primitives));
    if ($m->unresolved !== []) throw new \RuntimeException('unexpected unresolved: ' . implode(',', $m->unresolved));
});

$ok('skips dynamic Twig expressions into unresolved', static function () use ($extractor) {
    $twig = '<button ui="button" ui-tone="{{ toneVar }}">x</button>';
    $m = $extractor->extract($twig);
    if ($m->attrs !== []) throw new \RuntimeException('should not match dynamic value');
    if (count($m->unresolved) !== 1) throw new \RuntimeException('expected 1 unresolved');
});

$ok('flags out-of-vocabulary values', static function () use ($extractor) {
    $m = $extractor->extract('<div sx-gap="5">x</div>');  // 5 not in scale
    if ($m->attrs !== []) throw new \RuntimeException('should reject sx-gap=5');
    if (count($m->unresolved) !== 1) throw new \RuntimeException('expected unresolved');
});

$ok('flags unknown primitives', static function () use ($extractor) {
    $m = $extractor->extract('<div ui="unknown-thing">x</div>');
    if ($m->primitives !== []) throw new \RuntimeException('should reject unknown primitive');
    if (count($m->unresolved) !== 1) throw new \RuntimeException('expected unresolved');
});

$ok('dedupes repeated pairs', static function () use ($extractor) {
    $m = $extractor->extract('<a sx-gap="4"></a><b sx-gap="4"></b><c sx-gap="4"></c>');
    if (count($m->attrs) !== 1) throw new \RuntimeException('should dedupe');
});

echo "\n== Extract from primitives-demo.html (realistic page) ==\n";

$demoHtml = file_get_contents(__DIR__ . '/primitives-demo.html');
$manifest = $extractor->extract($demoHtml);

echo "  attrs used: " . count($manifest->attrs) . "\n";
echo "  primitives used: " . count($manifest->primitives) . " (" . implode(', ', $manifest->primitives) . ")\n";
echo "  unresolved: " . count($manifest->unresolved) . "\n";

$registry = new SliceRegistry();
$manifest->applyTo($registry);

echo "\n== BundleCompiler ==\n";

$ok('empty registry → minimal bundle (baseline + tokens only)', static function () use ($compiler) {
    $bundle = $compiler->compile(new SliceRegistry());
    if ($bundle->sliceIds !== [] || $bundle->primitiveIds !== []) {
        throw new \RuntimeException('expected no slice/primitive ids');
    }
    if (!str_contains($bundle->css, '@layer platform-ui.reset') || !str_contains($bundle->css, '--ui-accent-brand')) {
        throw new \RuntimeException('missing baseline/tokens');
    }
});

$ok('realistic page bundle', static function () use ($compiler, $registry) {
    $bundle = $compiler->compile($registry);
    $gzip = $bundle->gzipSize();
    echo "    full: {$bundle->byteSize()} bytes, gzipped: {$gzip} bytes\n";
    echo "    hash: {$bundle->hash}\n";
    echo "    grammar slices: " . count($bundle->sliceIds) . "\n";
    echo "    primitives: " . count($bundle->primitiveIds) . " (" . implode(', ', $bundle->primitiveIds) . ")\n";
    if ($gzip >= 8192) throw new \RuntimeException("M4 target missed: {$gzip} bytes gzipped >= 8KB");
    if (substr_count($bundle->css, '{') !== substr_count($bundle->css, '}')) {
        throw new \RuntimeException('unbalanced braces');
    }
});

$ok('deterministic hash — same registry twice → same hash', static function () use ($compiler) {
    $r = new SliceRegistry();
    $r->registerGrammar('sx-layout', 'stack');
    $r->registerPrimitive('button');
    $a = $compiler->compile($r)->hash;
    $b = $compiler->compile($r)->hash;
    if ($a !== $b) throw new \RuntimeException("{$a} vs {$b}");
});

$ok('different registry → different hash', static function () use ($compiler) {
    $r1 = new SliceRegistry(); $r1->registerGrammar('sx-layout', 'stack');
    $r2 = new SliceRegistry(); $r2->registerGrammar('sx-layout', 'cluster');
    if ($compiler->compile($r1)->hash === $compiler->compile($r2)->hash) {
        throw new \RuntimeException('hashes collided');
    }
});

echo "\n== Safelist ==\n";

$ok('global safelist adds slices', static function () use ($compiler) {
    $safelist = new Safelist();
    $safelist->addGlobalPrimitive('badge');
    $safelist->addGlobalSlice('sx-tone:warning');
    $registry = new SliceRegistry();
    $safelist->apply($registry);
    if (!in_array('badge', $registry->primitiveIds(), true)) throw new \RuntimeException('no badge');
    if (!in_array('sx-tone:warning', $registry->grammarSliceIds(), true)) throw new \RuntimeException('no tone');
});

$ok('route-scoped safelist only applies on matching route', static function () use ($compiler) {
    $safelist = new Safelist();
    $safelist->addRoutePrimitive('/dashboard', 'badge');
    $r = new SliceRegistry();
    $safelist->apply($r, '/other');
    if (in_array('badge', $r->primitiveIds(), true)) throw new \RuntimeException('should not apply');
    $safelist->apply($r, '/dashboard');
    if (!in_array('badge', $r->primitiveIds(), true)) throw new \RuntimeException('should apply');
});

echo "\n" . (empty($errors) ? "ALL OK" : "FAILURES:\n  " . implode("\n  ", $errors)) . "\n";
exit(empty($errors) ? 0 : 1);
