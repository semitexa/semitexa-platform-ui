<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Event;

/**
 * Render-time manifest produced for one component instance.
 *
 * `instanceId` ties the manifest to a specific DOM root (the same id is
 * emitted on the component root as `data-ui-component-instance-id="…"`).
 * The future runtime will scan for `[data-ui-event-manifest]` JSON scripts
 * and pair them with their roots via this id.
 *
 * Manifest version (`$schemaVersion`) is bumped only on a backward-
 * incompatible JSON-shape change; runtime should refuse unknown versions.
 */
final readonly class UiEventManifest
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        public string $componentName,
        public string $instanceId,
        /** @var list<UiEventManifestEntry> */
        public array $entries,
        public int $schemaVersion = self::SCHEMA_VERSION,
    ) {}

    /**
     * Plain-array projection used by the Twig helper for JSON encoding.
     *
     * @return array{v: int, c: string, i: string, events: list<array<string, mixed>>}
     */
    public function toJsonShape(): array
    {
        return [
            'v' => $this->schemaVersion,
            'c' => $this->componentName,
            'i' => $this->instanceId,
            'events' => array_map(
                static fn (UiEventManifestEntry $e): array => $e->toJsonShape(),
                $this->entries,
            ),
        ];
    }
}
