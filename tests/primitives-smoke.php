<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

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

$registry = new PrimitiveRegistry();

echo "== Registry ==\n";
$ok('6 primitives registered', static function () use ($registry) {
    $expected = ['button', 'input', 'label', 'field-shell', 'surface', 'badge'];
    $got = $registry->ids();
    sort($expected);
    sort($got);
    if ($expected !== $got) {
        throw new \RuntimeException('ids: ' . implode(',', $got));
    }
});

echo "\n== CSS files present ==\n";
foreach ($registry->all() as $p) {
    $ok("css {$p->id}", static function () use ($p) {
        if (!is_file($p->cssPath)) throw new \RuntimeException("missing: {$p->cssPath}");
        $css = file_get_contents($p->cssPath);
        if ($css === false || $css === '') throw new \RuntimeException('empty css');
        if (!str_contains($css, "[ui=\"{$p->id}\"]")) {
            throw new \RuntimeException("no [ui=\"{$p->id}\"] selector");
        }
        if (substr_count($css, '{') !== substr_count($css, '}')) {
            throw new \RuntimeException('unbalanced braces');
        }
        if (!str_contains($css, '@layer platform-ui.primitives')) {
            throw new \RuntimeException('missing @layer wrapper');
        }
    });
}

echo "\n== Twig templates present ==\n";
foreach ($registry->all() as $p) {
    $ok("twig {$p->id}", static function () use ($p) {
        if (!is_file($p->twigPath)) throw new \RuntimeException("missing: {$p->twigPath}");
        $twig = file_get_contents($p->twigPath);
        if (!str_contains($twig, '{% macro ')) throw new \RuntimeException('no macro');
    });
}

echo "\n== Button has expected variants/tones/sizes ==\n";
$button = $registry->get('button');
$ok('button variants', static function () use ($button) {
    if ($button->variants !== ['solid', 'soft', 'ghost']) {
        throw new \RuntimeException('got ' . implode(',', $button->variants));
    }
});
$ok('button tones (5)', static function () use ($button) {
    if (count($button->tones) !== 5) throw new \RuntimeException('got ' . count($button->tones));
});
$ok('button sizes (3)', static function () use ($button) {
    if (count($button->sizes) !== 3) throw new \RuntimeException('got ' . count($button->sizes));
});

echo "\n== Bundle compile ==\n";
$bundle = [];
$baseline = __DIR__ . '/../resources/baseline';
$bundle[] = file_get_contents($baseline . '/layers.css');
$bundle[] = file_get_contents($baseline . '/reset.css');
$bundle[] = file_get_contents($baseline . '/typography.css');
$bundle[] = file_get_contents(__DIR__ . '/../resources/skins/default/tokens.css');

// Grammar (from previous test artifact)
if (is_file(__DIR__ . '/grammar-demo.css')) {
    // Grammar already in demo — use it directly as reference, but for isolation we'll recompile
}

// Re-emit grammar slices inline for a complete bundle
$catalog = \Semitexa\PlatformUi\Application\Service\Css\Slice\SliceCatalog::withDefaults();
$grammarRules = [];
foreach ($catalog->emitAll() as $slice) {
    $grammarRules[] = $slice->css;
}
$bundle[] = "@layer platform-ui.grammar {\n" . implode("\n", $grammarRules) . "\n}";

// Primitives
foreach ($registry->all() as $p) {
    $bundle[] = file_get_contents($p->cssPath);
}

$fullCss = implode("\n\n", $bundle);
$ok('bundle compiles, balanced braces', static function () use ($fullCss) {
    if (substr_count($fullCss, '{') !== substr_count($fullCss, '}')) {
        throw new \RuntimeException('unbalanced in full bundle');
    }
});

file_put_contents(__DIR__ . '/primitives-demo.css', $fullCss);
echo "  wrote: " . __DIR__ . "/primitives-demo.css (" . strlen($fullCss) . " bytes, " . count($bundle) . " segments)\n";

echo "\n" . (empty($errors) ? "ALL OK" : "FAILURES:\n  " . implode("\n  ", $errors)) . "\n";
exit(empty($errors) ? 0 : 1);
