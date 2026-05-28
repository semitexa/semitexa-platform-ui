<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Css\Compiler;

use Semitexa\PlatformUi\Application\Service\Asset\CompiledBundle;
use Semitexa\PlatformUi\Application\Service\Asset\SliceRegistry;
use Semitexa\PlatformUi\Application\Service\Css\Slice\SliceCatalog;
use Semitexa\PlatformUi\Application\Service\Css\PrimitiveRegistry;

/**
 * Compiles a CSS bundle from a SliceRegistry.
 *
 * Order of assembly (respecting cascade layers):
 *   1. @layer declaration (fixed order: reset, tokens, grammar, primitives)
 *   2. baseline — reset.css + typography.css
 *   3. @layer platform-ui.grammar { used slices only }
 *   4. used primitives (each already @layer platform-ui.primitives)
 *
 * full.css is skin-neutral as of leaf 5c. Skin tokens load separately
 * via the theme resolver (theme_skin_css() → skins-base). Embedding
 * them here produced a stale `:root { --ui-*: ... }` layer that every
 * page immediately overrode. `$skinName` remains in compile()'s
 * signature for API compatibility but has no effect.
 */
final class BundleCompiler
{
    public function __construct(
        private readonly SliceCatalog $catalog,
        private readonly PrimitiveRegistry $primitives,
        private readonly string $resourcesPath,
    ) {
    }

    public function compile(
        SliceRegistry $registry,
        string $skinName = 'default',
        bool $includeBaseline = true,
    ): CompiledBundle {
        $segments = [];

        if ($includeBaseline) {
            $segments[] = $this->readFile('/baseline/layers.css');
            $segments[] = $this->readFile('/baseline/reset.css');
            $segments[] = $this->readFile('/baseline/typography.css');
        }

        // Skin tokens intentionally omitted — see class docblock.

        $grammarSliceIds = $registry->grammarSliceIds();
        sort($grammarSliceIds);
        if ($grammarSliceIds !== []) {
            $rules = [];
            foreach ($grammarSliceIds as $sliceId) {
                [$attr, $value] = explode(':', $sliceId, 2);
                $emitter = $this->catalog->emitter($attr);
                if ($emitter === null) {
                    continue;
                }
                try {
                    $rules[] = $emitter->emit($value)->css;
                } catch (\OutOfBoundsException) {
                    // silently skip invalid pairs (safelist may have stale ids)
                }
            }
            if ($rules !== []) {
                $segments[] = "@layer platform-ui.grammar {\n" . implode("\n", $rules) . "\n}";
            }
        }

        $primitiveIds = $registry->primitiveIds();
        sort($primitiveIds);
        foreach ($primitiveIds as $id) {
            $primitive = $this->primitives->get($id);
            if ($primitive === null || !is_file($primitive->cssPath)) {
                continue;
            }
            $css = file_get_contents($primitive->cssPath);
            if ($css !== false) {
                $segments[] = $css;
            }
        }

        $css = implode("\n\n", array_filter($segments, static fn(string $s): bool => $s !== ''));
        $hash = substr(hash('xxh128', $css), 0, 12);

        return new CompiledBundle(
            css: $css,
            hash: $hash,
            sliceIds: $grammarSliceIds,
            primitiveIds: $primitiveIds,
            skinName: $skinName,
        );
    }

    private function readFile(string $relative): string
    {
        $contents = @file_get_contents($this->resourcesPath . $relative);
        return $contents === false ? '' : $contents;
    }

    private function fileExists(string $relative): bool
    {
        return is_file($this->resourcesPath . $relative);
    }
}
