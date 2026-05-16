<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Primitive;

/**
 * Foundation-level event declaration for a UI primitive.
 *
 * Carried inside #[AsUiPrimitive(events: [...])].
 *
 * The semantic event $name (e.g. "click", "change") is what handlers bind to.
 * $native is the underlying DOM event(s); if null, the same string is used.
 * Full transport / response / value-rule semantics are described in the
 * framework-layer design — this DTO carries the foundation-level fields only.
 */
final readonly class UiPrimitiveEvent
{
    public function __construct(
        public string $name,
        public ?string $native = null,
        public ?string $payload = null,
        public UiEventTransport $transport = UiEventTransport::Http,
        public UiEventResponseMode $response = UiEventResponseMode::None,
        public ?int $debounceMs = null,
        public ?int $throttleMs = null,
    ) {}

    /**
     * Native DOM event name(s); defaults to the semantic event name.
     */
    public function nativeName(): string
    {
        return $this->native ?? $this->name;
    }
}
