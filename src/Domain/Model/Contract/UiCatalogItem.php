<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Contract;

use Semitexa\PlatformUi\Domain\Model\Behavior\BehaviorMetadata;
use Semitexa\PlatformUi\Domain\Model\Component\UiComponentMetadata;
use Semitexa\PlatformUi\Domain\Model\Primitive\PrimitiveMetadata;

/** A view of an existing registry entry, not a second registry. */
final readonly class UiCatalogItem
{
    public function __construct(
        public string $kind,
        public PrimitiveMetadata|UiComponentMetadata|BehaviorMetadata $metadata,
        public ?UiContract $contract,
        public ?string $template,
        public string $source,
        public int $line,
        public string $sourceHash,
    ) {}

    public function name(): string { return $this->metadata->name; }

    public function className(): string { return $this->metadata->class; }
}
