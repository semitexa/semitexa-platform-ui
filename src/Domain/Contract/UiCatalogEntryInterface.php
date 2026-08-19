<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Contract;

/**
 * What a UI catalog needs from an entry to index and deduplicate it: the
 * declaring class, the canonical name, and the `ui` alias.
 *
 * This is deliberately NOT a common base class for the metadata models.
 * `tk-ui-registry-twin-catalogs` recorded why: primitives and behaviors share
 * only these three field names — behaviors carry `requires`, primitives carry
 * `template`/`style` — and a shared base would couple two domain concepts that
 * may diverge. PHP 8.4 interface properties express exactly the shared half:
 * each `final readonly` model keeps its own shape and satisfies these hooks
 * with its plain public properties.
 */
interface UiCatalogEntryInterface
{
    public string $class { get; }

    public string $name { get; }

    public string $ui { get; }
}
