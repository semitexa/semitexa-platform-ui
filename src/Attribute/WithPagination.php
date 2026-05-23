<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Attribute;

use Attribute;

/**
 * Declares pagination defaults for a grid component.
 *
 * Placed on the class itself. `$defaultLimit` MUST appear in
 * `$limitOptions`; this is validated by GridComponentMetadataProvider
 * at boot.
 *
 *   - $defaultLimit  — initial page size.
 *   - $limitOptions  — selectable page sizes (non-empty list of
 *                      positive integers).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class WithPagination
{
    /**
     * @param list<int> $limitOptions
     */
    public function __construct(
        public readonly int $defaultLimit = 25,
        public readonly array $limitOptions = [10, 25, 50, 100],
    ) {}
}
