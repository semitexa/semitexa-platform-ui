<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Primitive;

/**
 * Immutable discovered-primitive metadata.
 *
 * Built from #[AsUiPrimitive] by the registry's discovery pass.
 * Holds the canonical $name, the CSS/markup $ui alias, the (optional)
 * template/script/style asset references, and any declared event capabilities.
 */
final readonly class PrimitiveMetadata
{
    public function __construct(
        public string $class,
        public string $name,
        public string $ui,
        public ?string $template,
        public ?string $script,
        public ?string $style,
        /** @var list<UiPrimitiveEvent> */
        public array $events,
    ) {}

    public function event(string $name): ?UiPrimitiveEvent
    {
        foreach ($this->events as $event) {
            if ($event->name === $name) {
                return $event;
            }
        }

        return null;
    }

    public function declaresEvent(string $name): bool
    {
        return $this->event($name) !== null;
    }

    /**
     * Plain-array view for debug / introspection. Server-side only; do not
     * project to the public manifest verbatim.
     *
     * @return array{
     *     class: string,
     *     name: string,
     *     ui: string,
     *     template: ?string,
     *     script: ?string,
     *     style: ?string,
     *     events: list<array{name: string, native: string, transport: string, response: string, payload: ?string, debounceMs: ?int, throttleMs: ?int}>
     * }
     */
    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'name' => $this->name,
            'ui' => $this->ui,
            'template' => $this->template,
            'script' => $this->script,
            'style' => $this->style,
            'events' => array_map(
                static fn (UiPrimitiveEvent $e): array => [
                    'name' => $e->name,
                    'native' => $e->nativeName(),
                    'transport' => $e->transport->value,
                    'response' => $e->response->value,
                    'payload' => $e->payload,
                    'debounceMs' => $e->debounceMs,
                    'throttleMs' => $e->throttleMs,
                ],
                $this->events,
            ),
        ];
    }
}
