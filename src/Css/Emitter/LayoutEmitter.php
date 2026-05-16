<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Css\Emitter;

use Semitexa\PlatformUi\Contract\SliceEmitter;
use Semitexa\PlatformUi\Css\Slice\Slice;

final class LayoutEmitter implements SliceEmitter
{
    private const VALUES = ['stack', 'cluster', 'grid', 'frame'];

    public function attribute(): string
    {
        return 'sx-layout';
    }

    public function allowedValues(): array
    {
        return self::VALUES;
    }

    public function emit(string $value): Slice
    {
        $css = match ($value) {
            'stack' => "[sx-layout=\"stack\"] { display: flex; flex-direction: column; }",
            'cluster' => "[sx-layout=\"cluster\"] { display: flex; flex-direction: row; flex-wrap: wrap; align-items: center; }",
            'grid' => "[sx-layout=\"grid\"] { display: grid; grid-template-columns: repeat(auto-fit, minmax(0, 1fr)); }",
            'frame' => "[sx-layout=\"frame\"] { display: block; }",
            default => throw new \OutOfBoundsException("Invalid sx-layout value: {$value}"),
        };

        return new Slice("sx-layout:{$value}", $css);
    }
}
