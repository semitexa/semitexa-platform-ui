<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Css\Slice;

final readonly class Slice
{
    public function __construct(
        public string $id,
        public string $css,
    ) {
    }
}
