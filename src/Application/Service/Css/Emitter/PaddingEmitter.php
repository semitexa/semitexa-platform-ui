<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Css\Emitter;

use Semitexa\PlatformUi\Contract\SliceEmitter;
use Semitexa\PlatformUi\Application\Service\Css\Slice\Slice;

final class PaddingEmitter implements SliceEmitter
{
    public function attribute(): string
    {
        return 'sx-padding';
    }

    public function allowedValues(): array
    {
        return array_map(strval(...), array_keys(GapEmitter::SCALE));
    }

    public function emit(string $value): Slice
    {
        $scale = GapEmitter::SCALE;
        if (!isset($scale[$value])) {
            throw new \OutOfBoundsException("Invalid sx-padding value: {$value}");
        }

        return new Slice(
            "sx-padding:{$value}",
            "[sx-padding=\"{$value}\"] { padding: " . $scale[$value] . "; }",
        );
    }
}
