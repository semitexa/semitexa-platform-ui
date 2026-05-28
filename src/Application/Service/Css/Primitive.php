<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Css;

final readonly class Primitive
{
    /**
     * @param list<string> $variants
     * @param list<string> $tones
     * @param list<string> $sizes
     * @param list<string> $states
     */
    public function __construct(
        public string $id,
        public string $cssPath,
        public string $twigPath,
        public array $variants = [],
        public array $tones = [],
        public array $sizes = [],
        public array $states = [],
    ) {
    }
}
