<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Css\Emitter;

use Semitexa\PlatformUi\Domain\Contract\SliceEmitterInterface;
use Semitexa\PlatformUi\Application\Service\Css\Slice\Slice;

final class AlignEmitter implements SliceEmitterInterface
{
    private const MAP = [
        'start' => 'flex-start',
        'center' => 'center',
        'end' => 'flex-end',
        'stretch' => 'stretch',
    ];

    public function attribute(): string
    {
        return 'sx-align';
    }

    public function allowedValues(): array
    {
        return array_keys(self::MAP);
    }

    public function emit(string $value): Slice
    {
        if (!isset(self::MAP[$value])) {
            throw new \OutOfBoundsException("Invalid sx-align value: {$value}");
        }

        return new Slice(
            "sx-align:{$value}",
            "[sx-align=\"{$value}\"] { align-items: " . self::MAP[$value] . "; }",
        );
    }
}
