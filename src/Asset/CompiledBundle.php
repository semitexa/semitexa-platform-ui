<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Asset;

final readonly class CompiledBundle
{
    /**
     * @param list<string> $sliceIds All slice-ids included in this bundle (for diagnostics)
     * @param list<string> $primitiveIds Primitive ids included
     */
    public function __construct(
        public string $css,
        public string $hash,
        public array $sliceIds,
        public array $primitiveIds,
        public string $skinName,
    ) {
    }

    public function byteSize(): int
    {
        return strlen($this->css);
    }

    public function gzipSize(): int
    {
        $gz = gzencode($this->css, 6);
        return $gz === false ? 0 : strlen($gz);
    }
}
