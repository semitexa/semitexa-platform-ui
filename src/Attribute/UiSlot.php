<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Attribute;

use Attribute;
use Semitexa\Core\Attribute\Capability;

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
#[Capability(
    id: 'ui.slot',
    summary: 'Declares a caller-provided content hole inside a component template.',
    useWhen: 'Callers must supply their own markup inside a component.',
    avoidWhen: 'The content is decided by the component itself.',
    replaces: [
        'passing markup as a pre-rendered HTML string prop',
    ],
)]
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class UiSlot
{
    public function __construct(
        public string $name,
        public ?string $description = null,
    ) {}
}
