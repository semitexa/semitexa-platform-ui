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
            $bodies[$name] = self::innerMarkup((string) file_get_contents($file));
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

        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new \RuntimeException(sprintf('Cannot create %s.', $targetDir));
        }
        foreach (glob($targetDir . '/*.json') ?: [] as $old) {
            unlink($old);
        }
        $count = 0;
        foreach ($shards as $char => $icons) {
            ksort($icons);
            $count += count($icons);
            self::json($targetDir . '/' . $char . '.json', $icons);
        }
        self::json($targetDir . '/aliases.json', $aliases);
        $keywords = [];
        foreach ($tags as $name => $words) {
            if (is_string($name) && isset($bodies[$name]) && is_array($words)) {
                $keywords[$name] = array_values(array_filter($words, 'is_string'));
            }
        }
        ksort($keywords);
        self::json($targetDir . '/tags.json', $keywords);
        copy($packageDir . '/LICENSE', $targetDir . '/LICENSE');
        $version = is_string($package['version'] ?? null) ? $package['version'] : 'unknown';
        file_put_contents($targetDir . '/VERSION', $version . "\n");

        return ['icons' => $count, 'aliases' => count($aliases), 'version' => $version];
    }

    /** The markup inside <svg>, whitespace between elements removed. */
    private static function innerMarkup(string $svg): string
    {
        if (preg_match('#<svg\b[^>]*>(.*)</svg>#s', $svg, $m) !== 1) {
            return '';
        }

        return str_replace(' />', '/>', (string) preg_replace('/>\s+</', '><', trim($m[1])));
    }

    /** @param array<string, mixed> $data */
    private static function json(string $path, array $data): void
    {
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    }
}
