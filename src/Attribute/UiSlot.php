<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Attribute;

use Attribute;

/**
 * Declares one named slot of a Platform UI component.
 *
 * Slots are caller-provided content holes inside a component's template.
 * They carry no event semantics in this slice — only static rendering.
 *
 *   - $name        — slot identifier, scoped to the owning component
 *                    (lowercase, kebab/underscore). Unique within one
 *                    component.
 *   - $description — short human-readable hint used by introspection
 *                    surfaces only.
 *
 * Slot content is wired through SSR's existing component-slot mechanism:
 * caller passes a `{name: value}` map as the third arg to the component()
 * Twig function; the template reads it back via `slot('name')`.
 *
 * Repeatable: a single component class declares one #[UiSlot] per slot.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class UiSlot
{
    public function __construct(
        public string $name,
        public ?string $description = null,
    ) {}
}
