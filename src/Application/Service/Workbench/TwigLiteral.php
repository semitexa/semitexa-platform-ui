<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Workbench;

/**
 * Writes a PHP value as a Twig expression literal, so a worked example can be
 * shown as the exact `{{ component(…) }}` call that reproduces it.
 *
 * Deterministic: the same value always yields the same text, which is what
 * lets a copied snippet be compared with the catalog.
 */
final class TwigLiteral
{
    public static function export(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            $value === true => 'true',
            $value === false => 'false',
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => self::string($value),
            is_array($value) => self::array($value),
            default => self::string(get_debug_type($value)),
        };
    }

    private static function string(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    /** @param array<array-key, mixed> $value */
    private static function array(array $value): string
    {
        if ($value === []) {
            return '[]';
        }
        if (array_is_list($value)) {
            return '[' . implode(', ', array_map(self::export(...), $value)) . ']';
        }
        $pairs = [];
        foreach ($value as $key => $item) {
            $name = is_string($key) && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $key) === 1
                ? $key
                : self::export((string) $key);
            $pairs[] = $name . ': ' . self::export($item);
        }
        return '{ ' . implode(', ', $pairs) . ' }';
    }
}
