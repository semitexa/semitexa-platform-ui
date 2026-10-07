<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Attribute;

use Attribute;
use Semitexa\Core\Attribute\Capability;

/**
 * A component property that is its state, kept on the server
 * (ep-platform-live-state · tk-ls-component-state).
 *
 *     #[AsComponent(name: 'shop.counter', template: '…')]
 *     final class CounterComponent
 *     {
 *         #[UiState]
 *         public int $count = 0;
 *
 *         #[UiOn(part: 'increment', event: 'click')]
 *         public function increment(UiInteractionEvent $event): void
 *         {
 *             $this->count++;
 *         }
 *     }
 *
 * - **Where it lives.** In the shared component-state store, keyed by the
 *   visitor's session and the instance. The page carries only a signed
 *   reference, not the values, whatever their size.
 * - **What a handler sees.** The property holds the state as last saved: a
 *   prop of the same name on the first render, every change since.
 * - **What a change does.** When a handler leaves a #[UiState] property
 *   changed, the state is saved and the component is drawn again with it,
 *   morphed in place — no effect to write.
 */
#[Capability(
    id: 'ui.component-state',
    summary: 'A component property kept on the server as its state: a handler changes it, and the component is saved and drawn again, morphed in place.',
    useWhen: 'A component remembers something between clicks — a count, a selection, a step — or its props are too large to ride every signed context.',
    avoidWhen: 'The value belongs to a record — save it through the ORM and let the page be live instead.',
    replaces: [
        'state carried in the page, copied into every signed context and capped at 4 KB',
        'a handler that reads its props, computes, and returns a re-render with the new value',
    ],
    seeAlso: 'ui.dashboard-widget',
)]
#[Attribute(Attribute::TARGET_PROPERTY)]
final class UiState
{
}
