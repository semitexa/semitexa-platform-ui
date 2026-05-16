<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Attribute;

use Attribute;

/**
 * Marks a component method as the deterministic provider of one named
 * #[UiPart]'s prop map.
 *
 * Provider contract (validated at metadata extraction):
 *   - `$part` must reference a #[UiPart] declared on the same component
 *     class; unknown parts fail loudly.
 *   - Only one provider per part. A second #[ProvidesUiPart(part: 'x')]
 *     on the same class is a registration-time error.
 *   - The provider method must be `public`, return `array`, and must not
 *     be static (it receives the component props as its argument).
 *   - In this slice, providers are pure: no IO, no service calls, no
 *     database access — return shape only depends on the props argument
 *     and possibly part metadata.
 *
 * Provider input is the caller-supplied component props. Provider output
 * is merged AFTER UiPart::defaults and BEFORE explicit caller overrides
 * (see UiPartPropResolver for the canonical order).
 *
 * Not repeatable — one #[ProvidesUiPart] per method.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class ProvidesUiPart
{
    public function __construct(
        public string $part,
    ) {}
}
