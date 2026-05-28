<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Attribute;

use Attribute;

/**
 * Class-level binding from an external service handler to one (component,
 * part, event) triple.
 *
 * Coexists with method-level #[UiOn]:
 *   - #[UiOn] on a public component method declares an inline handler
 *     intent — kept for dev/playground/demo cases where the handler logic
 *     belongs to the component itself.
 *   - #[HandlesUiEvent] on a separate service class (typically marked
 *     #[AsService] and implementing
 *     {@see \Semitexa\PlatformUi\Domain\Contract\UiEventHandlerInterface})
 *     binds that service to a (component, part, event) triple — used
 *     when the handler has dependencies, lives in a different module, or
 *     should not bloat a deliberately-thin component class.
 *
 * Identity:
 *   - $component — class-string of a #[AsComponent] class (the component
 *                  this binding targets, e.g. `GridComponent::class`).
 *                  The registry resolves this to the canonical component
 *                  name (e.g. "platform.grid") at discovery time.
 *   - $part      — name of a #[UiPart] declared on the component, or a
 *                  #[UiSlot] when the slot owns the interaction (e.g.
 *                  Grid's "filters" slot). Validated at discovery time.
 *   - $event     — semantic event name (same /^[a-z][a-z0-9:_-]*$/
 *                  shape #[UiOn] enforces).
 *   - $payload   — optional class-string of the typed payload the
 *                  dispatcher should deserialize the request body into
 *                  before invoking the handler. When null, the
 *                  dispatcher passes a generic envelope object.
 *
 * Repeatable: a service class may bind to multiple (component, part,
 * event) triples — useful when one handler covers filter/sort/paginate
 * for the same grid, for example. (componentName, partName, eventName)
 * must be unique across all class-level + method-level bindings; the
 * registry enforces this.
 *
 * Validation is performed at discovery time by UiComponentRegistry —
 * this attribute is a pure value carrier with no constructor checks.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class HandlesUiEvent
{
    public function __construct(
        /** @var class-string */
        public string $component,
        public string $part,
        public string $event,
        /** @var class-string|null */
        public ?string $payload = null,
    ) {}
}
