<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Component;

/**
 * Immutable Platform-UI-side metadata for a composed component.
 *
 * SSR's #[AsComponent] already holds (name, template, layout, …) for
 * rendering. This record holds the composition-only layer:
 *   - $parts keyed by part name (one per #[UiPart]);
 *   - $slots keyed by slot name (one per #[UiSlot]);
 *   - $providers keyed by part name (one per #[ProvidesUiPart]);
 *   - $events keyed by "<part>.<event>" (one per #[UiOn]).
 *
 * All maps preserve declaration order.
 */
final readonly class UiComponentMetadata
{
    public function __construct(
        public string $class,
        public string $name,
        /** @var array<string, UiPartMetadata> */
        public array $parts,
        /** @var array<string, UiSlotMetadata> */
        public array $slots,
        /** @var array<string, UiPartProviderMetadata> */
        public array $providers = [],
        /** @var array<string, UiOnMetadata> */
        public array $events = [],
    ) {}

    public function part(string $name): ?UiPartMetadata
    {
        return $this->parts[$name] ?? null;
    }

    public function slot(string $name): ?UiSlotMetadata
    {
        return $this->slots[$name] ?? null;
    }

    public function provider(string $partName): ?UiPartProviderMetadata
    {
        return $this->providers[$partName] ?? null;
    }

    public function event(string $partName, string $eventName): ?UiOnMetadata
    {
        return $this->events[$partName . '.' . $eventName] ?? null;
    }

    /** @return list<UiOnMetadata> */
    public function eventsForPart(string $partName): array
    {
        $found = [];
        foreach ($this->events as $event) {
            if ($event->partName === $partName) {
                $found[] = $event;
            }
        }
        return $found;
    }
}
