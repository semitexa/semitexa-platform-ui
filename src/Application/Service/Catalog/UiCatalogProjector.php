<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Catalog;

use InvalidArgumentException;
use ReflectionClass;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\PlatformUi\Application\Service\Behavior\UiBehaviorCatalog;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentCatalog;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveCatalog;
use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Behavior\BehaviorMetadata;
use Semitexa\PlatformUi\Domain\Model\Behavior\UiOptionType;
use Semitexa\PlatformUi\Domain\Model\Component\UiComponentMetadata;
use Semitexa\PlatformUi\Domain\Model\Contract\UiCatalogItem;
use Semitexa\PlatformUi\Domain\Model\Contract\UiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;
use Semitexa\PlatformUi\Domain\Model\Primitive\PrimitiveMetadata;
use Semitexa\Ssr\Attribute\AsComponent;

/** Server-side developer projection. Never embed the source/handler map in production pages. */
#[AsService]
final class UiCatalogProjector
{
    #[InjectAsReadonly]
    protected UiPrimitiveCatalog $primitives;

    #[InjectAsReadonly]
    protected UiComponentCatalog $components;

    #[InjectAsReadonly]
    protected UiBehaviorCatalog $behaviors;

    /** @return list<UiCatalogItem> */
    public function items(?string $kind = null): array
    {
        if ($kind !== null && !in_array($kind, ['primitive', 'component', 'behavior'], true)) {
            throw new InvalidArgumentException('Kind must be primitive, component or behavior.');
        }
        $items = [];
        foreach (['primitive' => $this->primitives, 'component' => $this->components, 'behavior' => $this->behaviors] as $type => $catalog) {
            if ($kind !== null && $kind !== $type) {
                continue;
            }
            foreach ($catalog->all() as $metadata) {
                $items[] = $this->item($type, $metadata);
            }
        }
        usort($items, static fn (UiCatalogItem $a, UiCatalogItem $b): int => strcmp($a->name(), $b->name()));
        return $items;
    }

    public function find(string $name): ?UiCatalogItem
    {
        foreach ($this->items() as $item) {
            if ($item->name() === $name) {
                return $item;
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    public function describe(UiCatalogItem $item): array
    {
        $metadata = $item->metadata;
        $contract = $item->contract;
        $parts = $slots = $events = [];
        if ($metadata instanceof UiComponentMetadata) {
            foreach ($metadata->parts as $name => $part) {
                $provider = $metadata->provider($name);
                $parts[$name] = [
                    'primitive' => $part->primitiveName,
                    'class' => $part->uses,
                    'defaults' => (object) $part->defaults,
                    'bind' => $part->bind === null ? null : (string) $part->bind,
                    'provider' => $provider === null ? null : $provider->class . '::' . $provider->method,
                    'precedence' => ['defaults', 'provider', 'non-null bind', 'caller overrides'],
                ];
            }
            foreach ($metadata->slots as $name => $slot) {
                $slots[$name] = ['description' => $slot->description];
            }
            foreach ($metadata->events as $event) {
                $primitive = $metadata->part($event->partName);
                $capability = $primitive === null ? null : $this->primitives->getByName($primitive->primitiveName)?->event($event->eventName);
                $events[$event->key()] = [
                    'part' => $event->partName, 'event' => $event->eventName,
                    'handler' => $event->class . '::' . $event->methodName,
                    'updates' => $event->updatesPath === null ? null : (string) $event->updatesPath,
                    'transport' => $capability?->transport->value,
                    'response' => $capability?->response->value,
                    'binding' => 'UiOn',
                ];
            }
            foreach ($this->components->externalBindingsFor($metadata->name) as $binding) {
                $events[$binding->key()] = [
                    'part' => $binding->partName, 'event' => $binding->eventName,
                    'handler' => $binding->handlerClass . '::handle',
                    'payload' => $binding->payloadClass, 'binding' => 'HandlesUiEvent',
                ];
            }
        } elseif ($metadata instanceof PrimitiveMetadata) {
            $events = $metadata->toArray()['events'];
        }
        $result = [
            'name' => $item->name(), 'kind' => $item->kind, 'class' => $item->className(),
            'typing' => $contract === null ? 'untyped' : 'typed',
            'summary' => $contract?->summary ?? '',
            'source' => ['file' => $item->source, 'line' => $item->line, 'sha256' => $item->sourceHash],
            'template' => $item->template,
            'props_schema' => $contract?->schema(), 'defaults' => (object) ($contract?->defaults() ?? []),
            'parts' => (object) $parts, 'slots' => (object) $slots, 'events' => (object) $events,
            'examples' => array_values(array_map(static fn ($example): array => $example->toArray(), $contract?->examples ?? [])),
            'preview_safe' => $contract?->previewSafe ?? false,
        ];
        if ($metadata instanceof BehaviorMetadata) {
            $result += $metadata->toArray();
        } elseif ($metadata instanceof PrimitiveMetadata) {
            $result += ['ui' => $metadata->ui, 'script' => $metadata->script, 'style' => $metadata->style];
        }
        $result['contract_version'] = hash('sha256', json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return $result;
    }

    /**
     * The shape is stated rather than left as array<string, mixed> so a caller
     * formatting entries gets array offsets it can actually reach.
     *
     * @return array{artifact: string, count: int, entries: list<array<string, mixed>>}
     */
    public function envelope(?string $kind = null, ?string $name = null): array
    {
        $items = array_values(array_filter($this->items($kind), static fn (UiCatalogItem $item): bool => $name === null || $item->name() === $name));
        if ($name !== null && $items === []) {
            throw new InvalidArgumentException("Unknown UI catalog entry: {$name}");
        }
        $entries = array_map($this->describe(...), $items);
        return ['artifact' => 'semitexa.platform-ui.catalog/v2', 'count' => count($entries), 'entries' => $entries];
    }

    private function item(string $kind, PrimitiveMetadata|UiComponentMetadata|BehaviorMetadata $metadata): UiCatalogItem
    {
        /** @var class-string $class */
        $class = $metadata->class;
        $reflection = new ReflectionClass($class);
        $attribute = $reflection->getAttributes(AsUiContract::class)[0] ?? null;
        $contract = $metadata instanceof UiComponentMetadata ? $metadata->contract : $attribute?->newInstance()->metadata();
        $template = $metadata instanceof PrimitiveMetadata ? $metadata->template : null;
        if ($metadata instanceof UiComponentMetadata) {
            $template = ($reflection->getAttributes(AsComponent::class)[0] ?? null)?->newInstance()->template;
        }
        if ($metadata instanceof BehaviorMetadata) {
            $props = [];
            foreach ($metadata->options as $option) {
                $type = match ($option->type) {
                    UiOptionType::Bool => UiPropType::Boolean,
                    UiOptionType::Number => UiPropType::Number,
                    // Behavior ListOf is a comma-separated string in the shipped grammar.
                    default => UiPropType::String,
                };
                $props[] = new UiProp($option->name, $type, default: $option->default, nullable: $option->default === null, values: $option->values, description: $option->description ?? '');
            }
            $contract = new UiContract($contract?->summary ?? $metadata->name, $props, array_values($contract?->examples ?? []), $contract?->previewSafe ?? false);
        }
        $file = $reflection->getFileName() ?: '';
        $root = rtrim((string) getcwd(), '/') . '/';
        return new UiCatalogItem($kind, $metadata, $contract, $template, str_starts_with($file, $root) ? substr($file, strlen($root)) : $file, $reflection->getStartLine() ?: 1, is_file($file) ? (hash_file('sha256', $file) ?: '') : '');
    }
}
