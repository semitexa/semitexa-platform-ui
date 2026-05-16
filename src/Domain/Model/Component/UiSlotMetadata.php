<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Component;

final readonly class UiSlotMetadata
{
    public function __construct(
        public string $name,
        public ?string $description,
    ) {}
}
