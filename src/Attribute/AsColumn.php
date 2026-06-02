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
 *   - $defaultSort — when set (`'asc'` / `'desc'`), this column is the grid's
 *                    DEFAULT sort and the value is its initial direction. The
 *                    resolved token (`${propertyName}_${defaultSort}`, e.g.
 *                    `submittedAt_desc`) is emitted by
 *                    GridComponentMetadataProvider as the `defaultSort` prop and
 *                    seeds the shell's initial `sort` (the same
 *                    declaration→metadata→bundle→runtime thread the declared
 *                    page-size travels). At most one column per grid may set it,
 *                    and it requires `sortable: true`.
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
        public readonly ?string $defaultSort = null,
    ) {
        if ($defaultSort !== null) {
            if (!in_array($defaultSort, ['asc', 'desc'], true)) {
                throw new \InvalidArgumentException(sprintf(
                    'AsColumn("%s"): defaultSort must be "asc" or "desc", got "%s".',
                    $label,
                    $defaultSort,
                ));
            }
            if (!$sortable) {
                throw new \InvalidArgumentException(sprintf(
                    'AsColumn("%s"): defaultSort requires sortable: true.',
                    $label,
                ));
            }
        }
    }
}
