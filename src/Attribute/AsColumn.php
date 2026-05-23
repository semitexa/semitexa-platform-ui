<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Attribute;

use Attribute;

/**
 * Declares one column on a grid component.
 *
 * Placed on a public property of a `#[AsComponent]` class; the property
 * name becomes the column key and `$type` drives rendering inside the
 * `platform.grid` template.
 *
 *   - $label    — header text shown above the column.
 *   - $sortable — whether the column header renders as a sort toggle.
 *   - $type     — one of `text`, `date`, `datetime`, `number`, `mono`.
 *
 * Read at boot by GridComponentMetadataProvider.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class AsColumn
{
    public function __construct(
        public readonly string $label,
        public readonly bool $sortable = false,
        public readonly string $type = 'text',
    ) {}
}
