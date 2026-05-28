<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Component;

/**
 * Immutable discovered metadata for one class-level #[HandlesUiEvent]
 * binding — the external-service counterpart of {@see UiOnMetadata}.
 *
 * Where UiOnMetadata wraps an inline method on the component class,
 * this DTO wraps a separate service class that implements
 * {@see \Semitexa\PlatformUi\Domain\Contract\UiEventHandlerInterface}.
 * The dispatcher resolves the handler service from the container at
 * dispatch time and invokes its `handle(payload, context)` method.
 *
 * `partName` may reference either a declared #[UiPart] or a declared
 * #[UiSlot] on the component — class-level bindings are allowed to
 * target slots (e.g. Grid's "filters" slot) where the interaction
 * happens on caller-provided content rather than a primitive part.
 */
final readonly class UiExternalHandlerMetadata
{
    public function __construct(
        public string $componentName,
        /** @var class-string */
        public string $componentClass,
        public string $partName,
        public string $eventName,
        /** @var class-string */
        public string $handlerClass,
        /** @var class-string|null */
        public ?string $payloadClass = null,
    ) {}

    /** Stable composite key used to index external bindings within one component. */
    public function key(): string
    {
        return $this->partName . '.' . $this->eventName;
    }
}
