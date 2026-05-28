<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Css\Emitter;

use Semitexa\PlatformUi\Domain\Contract\SliceEmitterInterface;
use Semitexa\PlatformUi\Application\Service\Css\Slice\Slice;

final class JustifyEmitter implements SliceEmitterInterface
{
    private const MAP = [
        'start' => 'flex-start',
        'center' => 'center',
        'end' => 'flex-end',
        'between' => 'space-between',
    ];

    public function attribute(): string
    {
        return 'sx-justify';
    }

    public function allowedValues(): array
    {
        return array_keys(self::MAP);
    }

    public function emit(string $value): Slice
    {
        if (!isset(self::MAP[$value])) {
            throw new \OutOfBoundsException("Invalid sx-justify value: {$value}");
        }

        return new Slice(
            "sx-justify:{$value}",
            "[sx-justify=\"{$value}\"] { justify-content: " . self::MAP[$value] . "; }",
        );
    }
}
