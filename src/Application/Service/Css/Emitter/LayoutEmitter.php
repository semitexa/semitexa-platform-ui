<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Css\Emitter;

use Semitexa\PlatformUi\Domain\Contract\SliceEmitterInterface;
use Semitexa\PlatformUi\Application\Service\Css\Slice\Slice;

final class LayoutEmitter implements SliceEmitterInterface
{
    private const VALUES = ['stack', 'cluster', 'grid', 'frame', 'container'];

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
            // Columns wrap once a column would be narrower than --ui-grid-min
            // (set it inline to tune). minmax(0, 1fr) never wrapped: every
            // child was squeezed into one row at any width.
            'grid' => "[sx-layout=\"grid\"] { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(var(--ui-grid-min, 16rem), 100%), 1fr)); }",
            'frame' => "[sx-layout=\"frame\"] { display: block; }",
            'container' => "[sx-layout=\"container\"] { display: block; width: 100%; max-width: var(--ui-container-max); margin-inline: auto; padding-inline: var(--ui-space-4); }",
            default => throw new \OutOfBoundsException("Invalid sx-layout value: {$value}"),
        };

        return new Slice("sx-layout:{$value}", $css);
    }
}
