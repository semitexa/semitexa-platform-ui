<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Css\Emitter;

use Semitexa\PlatformUi\Contract\SliceEmitter;
use Semitexa\PlatformUi\Css\Slice\Slice;

final class RadiusEmitter implements SliceEmitter
{
    /**
     * Fallback values used when a skin doesn't define `--ui-radius-<size>`.
     * Matches the historical hardcoded values so v1 skins (color-only)
     * render identically after Phase A tokenization.
     */
    private const SCALE = [
        'none' => '0',
        'sm' => '0.25rem',
        'md' => '0.5rem',
        'lg' => '0.75rem',
        'pill' => '9999px',
    ];

    public function attribute(): string
    {
        return 'sx-radius';
    }

    public function allowedValues(): array
    {
        return array_keys(self::SCALE);
    }

    public function emit(string $value): Slice
    {
        if (!isset(self::SCALE[$value])) {
            throw new \OutOfBoundsException("Invalid sx-radius value: {$value}");
        }

        $fallback = self::SCALE[$value];
        $css = "[sx-radius=\"{$value}\"] { border-radius: var(--ui-radius-{$value}, {$fallback}); }";

        return new Slice("sx-radius:{$value}", $css);
    }
}
