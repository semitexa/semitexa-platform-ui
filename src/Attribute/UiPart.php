<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Attribute;

use Attribute;

/**
 * Declares one named part of a Platform UI component.
 *
 * A part binds a logical role inside a component (e.g. "input", "label")
 * to a concrete primitive class. Identity rules:
 *
 *   - $name   — slot-like local identifier scoped to the owning component
 *               (lowercase, kebab/underscore). Unique within one component.
 *   - $uses   — fully-qualified class-name of a class marked with
 *               #[AsUiPrimitive]. FQCN keeps refactoring safe; alias lookup
 *               is reserved for Twig/demo surfaces.
 *   - $defaults — optional default props merged under any caller-provided
 *               props for this part.
 *   - $bind   — optional dot-separated value path (e.g. "value",
 *               "user.email") inside the component props that supplies
 *               the part's `value` prop. Server-rendered projection only
 *               in this slice — no live events, no two-way binding.
 *               Validated at metadata extraction by UiValuePath::parse().
 *
 * Repeatable: a single component class declares one #[UiPart] per part.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class UiPart
{
    public function __construct(
        public string $name,
        public string $uses,
        /** @var array<string, mixed> */
        public array $defaults = [],
        public ?string $bind = null,
    ) {}
}
