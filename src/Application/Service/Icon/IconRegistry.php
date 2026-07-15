<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Icon;

/**
 * The SX icon registry — a curated, Lucide-style stroke-icon set rendered as
 * inline SVG (currentColor, so a single glyph inherits text colour and scales
 * with font size). SSR-first: `icon('menu')` in Twig emits the SVG directly, no
 * client hydration or HTTP request. Extend at runtime with add().
 *
 * The set is data (resources/icons/lucide.json) so it can grow without code
 * changes; values are the inner SVG markup, wrapped here in a consistent 24x24
 * stroke-based envelope.
 */
final class IconRegistry
{
    /** @var array<string, string>|null */
    private static ?array $icons = null;

    /** @var array<string, string> runtime additions (win over the file set) */
    private static array $extra = [];

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        if (self::$icons === null) {
            self::$icons = [];
            $path = dirname(__DIR__, 4) . '/resources/icons/lucide.json';
            if (is_file($path)) {
                $data = json_decode((string) file_get_contents($path), true);
                if (is_array($data)) {
                    foreach ($data as $name => $svg) {
                        if (is_string($name) && $name !== '' && $name[0] !== '$' && is_string($svg)) {
                            self::$icons[$name] = $svg;
                        }
                    }
                }
            }
        }

        return self::$extra + self::$icons;
    }

    public static function get(string $name): ?string
    {
        return self::all()[$name] ?? null;
    }

    public static function has(string $name): bool
    {
        return self::get($name) !== null;
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        $names = array_keys(self::all());
        sort($names);

        return $names;
    }

    /** Add (or override) an icon at runtime — its inner SVG markup. */
    public static function add(string $name, string $innerSvg): void
    {
        self::$extra[$name] = $innerSvg;
    }

    /**
     * Render the named icon as inline SVG, or '' if unknown. A `label` makes it
     * an accessible image (role=img + aria-label); otherwise it is decorative
     * (aria-hidden). `size` is a px number or any CSS length; the icon uses
     * currentColor.
     *
     * @param array{size?: int|string, class?: string, label?: ?string, strokeWidth?: int|float} $opts
     */
    public static function render(string $name, array $opts = []): string
    {
        $inner = self::get($name);
        if ($inner === null) {
            return '';
        }

        $size = $opts['size'] ?? 24;
        $sizeAttr = is_int($size) ? (string) $size : (string) $size;
        $class = trim('sx-icon ' . (string) ($opts['class'] ?? ''));
        $strokeWidth = (string) ($opts['strokeWidth'] ?? 2);
        $label = $opts['label'] ?? null;

        $a11y = ($label !== null && trim((string) $label) !== '')
            ? sprintf('role="img" aria-label="%s"', htmlspecialchars((string) $label, ENT_QUOTES))
            : 'aria-hidden="true" focusable="false"';

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="%s" height="%s" fill="none" stroke="currentColor" stroke-width="%s" stroke-linecap="round" stroke-linejoin="round" class="%s" %s>%s</svg>',
            htmlspecialchars($sizeAttr, ENT_QUOTES),
            htmlspecialchars($sizeAttr, ENT_QUOTES),
            htmlspecialchars($strokeWidth, ENT_QUOTES),
            htmlspecialchars(trim($class), ENT_QUOTES),
            $a11y,
            $inner,
        );
    }
}
