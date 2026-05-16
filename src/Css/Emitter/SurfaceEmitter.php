<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Css\Emitter;

use Semitexa\PlatformUi\Contract\SliceEmitter;
use Semitexa\PlatformUi\Css\Slice\Slice;

final class SurfaceEmitter implements SliceEmitter
{
    private const VALUES = ['flat', 'panel', 'raised'];

    public function attribute(): string
    {
        return 'sx-surface';
    }

    public function allowedValues(): array
    {
        return self::VALUES;
    }

    public function emit(string $value): Slice
    {
        $css = match ($value) {
            'flat' => "[sx-surface=\"flat\"] { background: var(--ui-surface-page); color: var(--ui-text-primary); }",
            'panel' => "[sx-surface=\"panel\"] { background: var(--ui-surface-panel); color: var(--ui-text-primary); border: 1px solid var(--ui-border-subtle); }",
            'raised' => "[sx-surface=\"raised\"] { background: var(--ui-surface-raised); color: var(--ui-text-primary); border: 1px solid var(--ui-border-subtle); box-shadow: var(--ui-shadow-sm, 0 1px 3px rgba(0, 0, 0, 0.06)); }",
            default => throw new \OutOfBoundsException("Invalid sx-surface value: {$value}"),
        };

        return new Slice("sx-surface:{$value}", $css);
    }
}
