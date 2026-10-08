<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Icon;

/**
 * Turns an unpacked `lucide-static` package into the registry's data:
 *
 *   lucide/<first char>.json  canonical icons, name → inner SVG markup (sharded,
 *                             so a worker loads only the shards a page uses)
 *   lucide/aliases.json       old / alternative name → canonical name
 *   lucide/tags.json          canonical name → search keywords (catalog, palette)
 *   lucide/LICENSE, VERSION   the ISC licence travels with the glyphs
 *
 * Canonical = listed in the package's tags.json; any other file whose markup
 * equals a canonical icon's is an alias of it.
 */
final class LucideIconSetWriter
{
    /** @return array{icons: int, aliases: int, version: string} */
    public function write(string $packageDir, string $targetDir): array
    {
        $iconsDir = $packageDir . '/icons';
        $tags = json_decode((string) @file_get_contents($packageDir . '/tags.json'), true);
        $package = json_decode((string) @file_get_contents($packageDir . '/package.json'), true);
        if (!is_dir($iconsDir) || !is_array($tags) || !is_array($package)) {
            throw new \RuntimeException(sprintf('%s is not an unpacked lucide-static package.', $packageDir));
        }

        $bodies = [];
        foreach (glob($iconsDir . '/*.svg') ?: [] as $file) {
            $name = basename($file, '.svg');
            if (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $name) !== 1) {
                continue;
            }
            $svg = @file_get_contents($file);
            $markup = is_string($svg) ? self::innerMarkup($svg) : null;
            if ($markup === null) {
                // A blank glyph published as a successful sync is worse than a failed sync.
                throw new \RuntimeException(sprintf('%s cannot be read or is not an SVG.', $file));
            }
            $bodies[$name] = $markup;
        }

        $shards = [];
        $byBody = [];
        foreach ($tags as $name => $keywords) {
            if (!is_string($name) || !isset($bodies[$name])) {
                continue;
            }
            $shards[$name[0]][$name] = $bodies[$name];
            $byBody[$bodies[$name]] ??= $name;
        }
        $aliases = [];
        foreach ($bodies as $name => $body) {
            if (!isset($tags[$name]) && isset($byBody[$body])) {
                $aliases[$name] = $byBody[$body];
            }
        }
        ksort($aliases);

        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new \RuntimeException(sprintf('Cannot create %s.', $targetDir));
        }
        // Everything is written to a staging directory first; the current catalog
        // is replaced only once the whole new one is on disk.
        $stage = $targetDir . '/.sync-' . bin2hex(random_bytes(4));
        if (!@mkdir($stage, 0775)) {
            throw new \RuntimeException(sprintf('Cannot create %s.', $stage));
        }
        try {
            $count = 0;
            foreach ($shards as $char => $icons) {
                ksort($icons);
                $count += count($icons);
                self::json($stage . '/' . $char . '.json', $icons);
            }
            self::json($stage . '/aliases.json', $aliases);
            $keywords = [];
            foreach ($tags as $name => $words) {
                if (is_string($name) && isset($bodies[$name]) && is_array($words)) {
                    $keywords[$name] = array_values(array_filter($words, 'is_string'));
                }
            }
            ksort($keywords);
            self::json($stage . '/tags.json', $keywords);
            if (!@copy($packageDir . '/LICENSE', $stage . '/LICENSE')) {
                throw new \RuntimeException(sprintf('Cannot copy %s/LICENSE.', $packageDir));
            }
            $version = is_string($package['version'] ?? null) ? $package['version'] : 'unknown';
            self::put($stage . '/VERSION', $version . "\n");

            foreach (glob($targetDir . '/*.json') ?: [] as $old) {
                unlink($old);
            }
            foreach (scandir($stage) ?: [] as $file) {
                if ($file !== '.' && $file !== '..' && !@rename($stage . '/' . $file, $targetDir . '/' . $file)) {
                    throw new \RuntimeException(sprintf('Cannot move %s into %s.', $file, $targetDir));
                }
            }
        } finally {
            foreach (glob($stage . '/*') ?: [] as $left) {
                @unlink($left);
            }
            @rmdir($stage);
        }

        return ['icons' => $count, 'aliases' => count($aliases), 'version' => $version];
    }

    /** The markup inside <svg>, whitespace between elements removed. */
    private static function innerMarkup(string $svg): ?string
    {
        if (preg_match('#<svg\b[^>]*>(.*)</svg>#s', $svg, $m) !== 1 || trim($m[1]) === '') {
            return null;
        }

        return str_replace(' />', '/>', (string) preg_replace('/>\s+</', '><', trim($m[1])));
    }

    /** @param array<string, mixed> $data */
    private static function json(string $path, array $data): void
    {
        self::put($path, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    }

    private static function put(string $path, string $contents): void
    {
        if (@file_put_contents($path, $contents) !== strlen($contents)) {
            throw new \RuntimeException(sprintf('Cannot write %s.', $path));
        }
    }
}
