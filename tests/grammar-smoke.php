<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

use Semitexa\PlatformUi\Css\Slice\SliceCatalog;
use Semitexa\PlatformUi\Support\ValueValidator;

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

echo "== Catalog registration ==\n";
$ok('9 attributes registered', static function () use ($catalog) {
    $expected = ['sx-layout', 'sx-gap', 'sx-padding', 'sx-radius', 'sx-surface', 'sx-tone', 'ui-text', 'sx-align', 'sx-justify'];
    $got = $catalog->attributes();
    sort($expected);
    sort($got);
    if ($expected !== $got) {
        throw new \RuntimeException('attrs: ' . implode(',', $got));
    }
});

echo "\n== Slice emission — all values ==\n";
$totalSlices = 0;
$allCss = [];
foreach ($catalog->emitAll() as $slice) {
    $totalSlices++;
    $allCss[] = $slice->css;
    $ok("emit {$slice->id}", static function () use ($slice) {
        if (!str_contains($slice->css, '{') || !str_contains($slice->css, '}')) {
            throw new \RuntimeException("no CSS rule: {$slice->css}");
        }
        if (substr_count($slice->css, '{') !== substr_count($slice->css, '}')) {
            throw new \RuntimeException("unbalanced braces");
        }
    });
}

echo "\n== ValueValidator ==\n";
$validator = new ValueValidator($catalog);
$ok('sx-gap=4 is valid', static function () use ($validator) {
    if (!$validator->isValid('sx-gap', '4')) throw new \RuntimeException('should be valid');
});
$ok('sx-gap=5 is invalid (not in scale)', static function () use ($validator) {
    if ($validator->isValid('sx-gap', '5')) throw new \RuntimeException('should be invalid');
});
$ok('unknown attribute rejected', static function () use ($validator) {
    if ($validator->isValid('sx-bogus', 'x')) throw new \RuntimeException('should be invalid');
});
$ok('assert() throws with suggestion', static function () use ($validator) {
    try {
        $validator->assert('sx-radius', 'xxl');
        throw new \RuntimeException('should have thrown');
    } catch (\InvalidArgumentException $e) {
        if (!str_contains($e->getMessage(), 'none, sm, md, lg, pill')) {
            throw new \RuntimeException('missing suggestion: ' . $e->getMessage());
        }
    }
});

echo "\n== Compile all slices → demo CSS ==\n";
$demoCss = implode("\n", $allCss);
$ok("non-empty compiled CSS", static function () use ($demoCss, $totalSlices) {
    if (strlen($demoCss) < 500) throw new \RuntimeException('suspiciously small');
    echo "  ({$totalSlices} slices, " . strlen($demoCss) . " bytes)\n";
});

// Write out a complete stylesheet: layers + reset + typography + tokens + grammar.
$baselineDir = __DIR__ . '/../resources/baseline';
$skinTokens = __DIR__ . '/../resources/skins/default/tokens.css';
$bundle = implode("\n\n", [
    file_get_contents($baselineDir . '/layers.css'),
    file_get_contents($baselineDir . '/reset.css'),
    file_get_contents($baselineDir . '/typography.css'),
    file_get_contents($skinTokens),
    "@layer platform-ui.grammar {\n" . implode("\n", $allCss) . "\n}",
]);

file_put_contents(__DIR__ . '/grammar-demo.css', $bundle);
echo "  wrote: " . __DIR__ . "/grammar-demo.css (" . strlen($bundle) . " bytes)\n";

echo "\n" . (empty($errors) ? "ALL OK" : "FAILURES:\n  " . implode("\n  ", $errors)) . "\n";
exit(empty($errors) ? 0 : 1);
