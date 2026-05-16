<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Css\Extractor;

use Semitexa\PlatformUi\Css\Slice\SliceCatalog;
use Semitexa\PlatformUi\Primitive\PrimitiveRegistry;

final class TwigExtractor
{
    private const ATTR_PATTERN = '/\b(ui|sx-[a-z-]+|ui-[a-z-]+)="([^"]+)"/';

    /** Attributes baked into each primitive's CSS — not separate grammar slices. */
    private const PRIMITIVE_MODIFIERS = ['ui-variant', 'ui-tone', 'ui-size', 'ui-state'];

    public function __construct(
        private readonly SliceCatalog $catalog,
        private readonly PrimitiveRegistry $primitives,
    ) {
    }

    public function extract(string $source): UsageManifest
    {
        $attrs = [];
        $primitives = [];
        $unresolved = [];

        if (preg_match_all(self::ATTR_PATTERN, $source, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $attr = $m[1];
                $value = $m[2];

                if ($this->isDynamic($value)) {
                    $unresolved[] = "{$attr}=\"{$value}\"";
                    continue;
                }

                if ($attr === 'ui') {
                    if ($this->primitives->get($value) !== null) {
                        $primitives[$value] = true;
                    } else {
                        $unresolved[] = "ui=\"{$value}\"";
                    }
                    continue;
                }

                if (in_array($attr, self::PRIMITIVE_MODIFIERS, true)) {
                    continue;
                }

                $emitter = $this->catalog->emitter($attr);
                if ($emitter === null) {
                    $unresolved[] = "{$attr}=\"{$value}\" (unknown attribute)";
                    continue;
                }

                if (!in_array($value, $emitter->allowedValues(), true)) {
                    $unresolved[] = "{$attr}=\"{$value}\" (not in vocabulary)";
                    continue;
                }

                $attrs[] = ['attr' => $attr, 'value' => $value];
            }
        }

        return new UsageManifest(
            attrs: $this->dedupeAttrs($attrs),
            primitives: array_keys($primitives),
            unresolved: array_values(array_unique($unresolved)),
        );
    }

    public function extractFile(string $path): UsageManifest
    {
        $source = @file_get_contents($path);
        if ($source === false) {
            throw new \RuntimeException("Cannot read template: {$path}");
        }
        return $this->extract($source);
    }

    private function isDynamic(string $value): bool
    {
        return str_contains($value, '{{') || str_contains($value, '{%');
    }

    /**
     * @param list<array{attr: string, value: string}> $attrs
     * @return list<array{attr: string, value: string}>
     */
    private function dedupeAttrs(array $attrs): array
    {
        $seen = [];
        $out = [];
        foreach ($attrs as $pair) {
            $key = $pair['attr'] . ':' . $pair['value'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $pair;
            }
        }
        return $out;
    }
}
