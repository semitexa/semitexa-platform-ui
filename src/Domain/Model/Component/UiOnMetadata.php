<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Component;

/**
 * Immutable discovered metadata for one #[UiOn] declaration.
 *
 * This DTO is backend-only. It MUST NOT be projected to the DOM verbatim
 * in this slice — no transport URL, no signed context, no handler-id.
 * The fields are designed so that a future event pipeline can derive
 * everything it needs (component identity, part identity, event name,
 * which value the event updates, which PHP method handles it).
 */
final readonly class UiOnMetadata
{
    public function __construct(
        public string $componentName,
        /** @var class-string */
        public string $class,
        public string $partName,
        public string $eventName,
        /** Final updates path: either explicit or inferred from part bind. */
        public ?UiValuePath $updatesPath,
        public string $methodName,
    ) {}

    /** Stable composite key used to index events within one component. */
    public function key(): string
    {
        return $this->partName . '.' . $this->eventName;
    }
}
