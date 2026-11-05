<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Attribute;

use Attribute;
use Semitexa\Core\Attribute\Capability;

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
#[Capability(
    id: 'ui.part',
    summary: 'Declares one named part of a component and binds it to a primitive, with defaults and an optional value path.',
    useWhen: 'A component is assembled from smaller primitives that callers may configure.',
    avoidWhen: 'The child markup is fixed and callers are never meant to configure it.',
    replaces: [
        'hard-coding child markup inside the component template',
    ],
    seeAlso: 'ui.primitive',
    usage: <<<'USAGE'
        #[UiPart(name: 'save', uses: ButtonPrimitive::class, defaults: ['variant' => 'solid'])]
        #[UiPart(name: 'email', uses: InputPrimitive::class, bind: 'user.email')]
        final class ProfileFormComponent { ... }

        // In the component's template. Props given here win over defaults:
        {{ ui_part('email') }}
        {{ ui_part('save', {label: 'Save'}) }}

        // bind: fills the part's value from the component's props when it renders; it does not
        // write back. To react to a part, add #[UiOn]: ai:ask mechanisms --id=ui.event-intent.
        USAGE,
)]
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
