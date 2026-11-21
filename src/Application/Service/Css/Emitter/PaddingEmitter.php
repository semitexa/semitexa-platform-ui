<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Css\Emitter;

use Semitexa\PlatformUi\Domain\Contract\SliceEmitterInterface;
use Semitexa\PlatformUi\Application\Service\Css\Slice\Slice;

final class PaddingEmitter implements SliceEmitterInterface
{
    /**
     * The gap scale and step 5: the grid's empty state pads itself with
     * sx-padding="5" (grid-runtime-v2.js), a step the shared scale never had,
     * so "No rows." sat on the panel border of every empty grid.
     */
    public const SCALE = [
        '0' => '0',
        '1' => 'var(--ui-space-1)',
        '2' => 'var(--ui-space-2)',
        '3' => 'var(--ui-space-3)',
        '4' => 'var(--ui-space-4)',
        '5' => 'var(--ui-space-5)',
        '6' => 'var(--ui-space-6)',
        '8' => 'var(--ui-space-8)',
    ];

    public function attribute(): string
    {
        return 'sx-padding';
    }

    public function allowedValues(): array
    {
        return array_map(strval(...), array_keys(self::SCALE));
    }

    public function emit(string $value): Slice
    {
        $scale = self::SCALE;
        if (!isset($scale[$value])) {
            throw new \OutOfBoundsException("Invalid sx-padding value: {$value}");
        }

        return new Slice(
            "sx-padding:{$value}",
            "[sx-padding=\"{$value}\"] { padding: " . $scale[$value] . "; }",
        );
    }
}
