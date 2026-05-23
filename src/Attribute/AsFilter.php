<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Attribute;

use Attribute;

/**
 * Declares one filter on a grid component.
 *
 * Placed on a public property of a `#[AsComponent]` class; the property
 * name becomes the filter field name and `$type` selects the input
 * primitive rendered by the grid template.
 *
 *   - $type        — one of `search`, `select`.
 *   - $placeholder — placeholder text for the input.
 *   - $label       — visible label / accessible name.
 *
 * Read at boot by GridComponentMetadataProvider.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class AsFilter
{
    public function __construct(
        public readonly string $type,
        public readonly string $placeholder = '',
        public readonly string $label = '',
    ) {}
}
