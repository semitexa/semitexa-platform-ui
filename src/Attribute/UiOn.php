<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Attribute;

use Attribute;
use Semitexa\Core\Attribute\Capability;

/**
 * Declares — as metadata only — which (part, event) pair a method is the
 * intended handler for.
 *
 * Identity:
 *   - $part    — name of a #[UiPart] declared on the same component.
 *                The factory rejects unknown parts at metadata-extraction
 *                time.
 *   - $event   — semantic event name (e.g. "change", "click", "blur").
 *                Validated against /^[a-z][a-z0-9:_-]*\$/ — no spaces,
 *                no uppercase, no JavaScript, no Twig delimiters,
 *                no brackets or quotes.
 *   - $updates — optional dot-separated UiValuePath that this event
 *                updates. When omitted AND the target part declares a
 *                bind path, the bind path is inferred. When explicitly
 *                provided AND the part is bound, the path MUST equal
 *                the part bind path (strict-compatibility mode in this
 *                slice).
 *
 * Targets a public, non-static, non-abstract instance method. The method
 * is NOT invoked in this slice — it exists purely as a discoverable
 * handler-intent marker for future runtime steps.
 *
 * Not repeatable on a single method. A class may declare multiple UiOn
 * handlers across different methods, but each (part, event) pair is
 * unique within one component (enforced by the factory).
 */
#[Capability(
    id: 'ui.event-intent',
    summary: 'Declares which (part, event) pair a component method is the intended handler for, and optionally the value path it updates.',
    useWhen: 'The handler logic belongs to the component itself.',
    avoidWhen: 'The handler needs services or belongs to another module - bind a service class instead.',
    replaces: [
        'wiring an event listener in page JavaScript and posting to an ad-hoc route',
    ],
    seeAlso: 'ui.event-handler',
    usage: <<<'USAGE'
        #[AsComponent(name: 'shop.counter', template: '@project-layouts-Shop/counter.html.twig')]
        #[UiPart(name: 'increment', uses: ButtonPrimitive::class)]
        final class CounterComponent
        {
            #[UiState]
            public int $count = 0;

            #[UiOn(part: 'increment', event: 'click')]
            public function onIncrement(UiInteractionEvent $event): void
            {
                $this->count++;   // the component is drawn again with it and morphed in place
            }
        }

        // Its template. Without the manifest line nothing fires, and nothing says so:
        {%- set _ui_instance = ui_component_instance_for(instanceId|default(null)) -%}
        <div data-ui-component="shop.counter" data-ui-component-instance-id="{{ _ui_instance }}">
            {{ ui_part('increment', {label: '+1'}) }}
            {{ ui_event_manifest(_ui_instance) }}
        </div>

        // The page calls {{ ui_page_sse_session_meta() }}.

        // Data in: $event->props(), $event->value(), and $event->formValues for a form part.
        // Data out: return UiInteractionResult::patch([UiResponsePatch::rerender($event->instanceId, $changedProps)]),
        // or ::toast(), ::redirect(). An input can debounce: #[UiOn(part: 'search', event: 'input', debounce: 200)].

        // A part can repeat per row. The element's value says which row, so write the element
        // itself, ui_part() does not pass value on:
        <button type="button" data-ui-part="remove" value="{{ line.id }}">Remove</button>
        // A row that can come and go carries a stable key, or a re-render pairs rows by position
        // and a half-typed draft lands on the next row:
        <li data-key="{{ line.id }}">...</li>
        // and read $event->value(). A patch from a stream has no row: to change one row from the
        // server, give it <span data-ui-patch-target="total-{{ line.id }}"> and aim at that name.
        // A child cannot rerender its parent.
        USAGE,
)]
#[Attribute(Attribute::TARGET_METHOD)]
final class UiOn
{
    public function __construct(
        public string $part,
        public string $event,
        public ?string $updates = null,
        /**
         * Send only after the user paused this long (ms) — as-you-type input
         * without a request per keystroke. A pending send fires at once when
         * the enclosing form submits.
         */
        public ?int $debounce = null,
        /** Send at most once per this many ms (first immediately, last too). */
        public ?int $throttle = null,
    ) {}
}
