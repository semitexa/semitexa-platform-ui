<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Css\Emitter;

use Semitexa\PlatformUi\Domain\Contract\SliceEmitterInterface;
use Semitexa\PlatformUi\Application\Service\Css\Slice\Slice;

final class GapEmitter implements SliceEmitterInterface
{
    public const SCALE = [
        '0' => '0',
        '1' => 'var(--ui-space-1)',
        '2' => 'var(--ui-space-2)',
        '3' => 'var(--ui-space-3)',
        '4' => 'var(--ui-space-4)',
        '6' => 'var(--ui-space-6)',
        '8' => 'var(--ui-space-8)',
    ];

    public function attribute(): string
    {
        return 'sx-gap';
    }

    public function allowedValues(): array
    {
        return array_map(strval(...), array_keys(self::SCALE));
    }

    public function emit(string $value): Slice
    {
        if (!isset(self::SCALE[$value])) {
            throw new \OutOfBoundsException("Invalid sx-gap value: {$value}");
        }

        return new Slice(
            "sx-gap:{$value}",
            "[sx-gap=\"{$value}\"] { gap: " . self::SCALE[$value] . "; }",
        );
    }
}
