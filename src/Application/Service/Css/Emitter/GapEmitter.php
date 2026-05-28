<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Css\Emitter;

use Semitexa\PlatformUi\Contract\SliceEmitter;
use Semitexa\PlatformUi\Application\Service\Css\Slice\Slice;

final class GapEmitter implements SliceEmitter
{
    public const SCALE = [
        '0' => '0',
        '1' => '0.25rem',
        '2' => '0.5rem',
        '3' => '0.75rem',
        '4' => '1rem',
        '6' => '1.5rem',
        '8' => '2rem',
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
