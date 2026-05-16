<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Contract;

use Semitexa\PlatformUi\Css\Slice\Slice;

interface SliceEmitter
{
    public function attribute(): string;

    /** @return list<string> */
    public function allowedValues(): array;

    public function emit(string $value): Slice;
}
