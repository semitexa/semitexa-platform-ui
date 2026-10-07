<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Tree;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\PlatformUi\Domain\Model\Contract\UiCatalogItem;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Tree\UiTree;

/**
 * What an agent is told about the components it may compose a screen from
 * (tk-ai-catalog) — the visitor's view of the agent catalog, and only that:
 * a component the visitor may not use is not mentioned at all.
 *
 * - {@see components()}: each with its summary, props, slots and an example;
 * - {@see treeSchema()}: the JSON Schema of a whole tree, a node being one of
 *   the open components with its own props schema;
 * - {@see describe()}: a compact text of the same, for a prompt.
 */
#[AsService]
final class UiAgentManifest
{
    public const ARTIFACT = 'semitexa.platform-ui.agent-manifest/v1';

    #[InjectAsReadonly]
    protected UiAgentCatalog $catalog;

    #[InjectAsReadonly]
    protected UiTreeActions $actions;

    /** @return list<string> the action kinds a tree may name */
    public function actionKinds(): array
    {
        return $this->actions->kinds();
    }

    /** The action kinds, one line each, for a prompt. */
    public function describeActions(): string
    {
        return $this->actions->describe();
    }

    /** @return list<array{name: string, kind: string, summary: string, props: array<string, mixed>, slots: list<string>, example: ?array<string, mixed>}> */
    public function components(): array
    {
        $out = [];
        foreach ($this->catalog->forVisitor() as $name => $item) {
            $contract = $item->contract;
            $example = $contract === null || $contract->examples === [] ? null : $contract->exampleArray(array_values($contract->examples)[0])['props'];
            $out[] = [
                'name' => $name,
                'kind' => $item->kind,
                'summary' => $contract->summary ?? '',
                'props' => $contract?->schema() ?? ['type' => 'object'],
                'slots' => UiAgentCatalog::slotsOf($item),
                'example' => $example === null ? null : (array) json_decode((string) json_encode($example), true),
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $out;
    }

    /** @return array<string, mixed> the JSON Schema (2020-12) of a tree this visitor may be shown */
    public function treeSchema(): array
    {
        $binding = ['type' => 'object', 'properties' => ['$data' => ['type' => 'string', 'description' => 'A JSON Pointer into data']], 'required' => ['$data'], 'additionalProperties' => false];
        $action = ['type' => 'object', 'properties' => ['$action' => ['type' => 'string', 'description' => 'The name of one of actions']], 'required' => ['$action'], 'additionalProperties' => false];
        $nodes = [];
        foreach ($this->catalog->forVisitor() as $name => $item) {
            /** @var array<string, mixed> $props */
            $props = json_decode((string) json_encode($item->contract?->schema() ?? ['type' => 'object']), true);
            unset($props['$schema']);
            $properties = is_array($props['properties'] ?? null) ? $props['properties'] : [];
            foreach ($properties as $prop => $schema) {
                $properties[$prop] = ['anyOf' => [$schema, $binding, $action]];
            }
            $props['properties'] = $properties === [] ? new \stdClass() : $properties;
            $slots = UiAgentCatalog::slotsOf($item);
            $nodes[] = [
                'type' => 'object',
                'properties' => [
                    'type' => ['const' => $name],
                    'props' => $props,
                    'children' => $slots === [] ? ['type' => 'array', 'maxItems' => 0] : ['type' => 'array', 'items' => ['type' => 'string']],
                    'slot' => ['type' => 'string', 'description' => 'One of the parent\'s slots; none: its default'],
                ],
                'required' => ['type'],
                'additionalProperties' => false,
            ];
        }

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'Semitexa UI tree',
            'type' => 'object',
            'properties' => [
                'version' => ['const' => UiTree::VERSION],
                'root' => ['type' => 'string'],
                'nodes' => ['type' => 'object', 'additionalProperties' => ['oneOf' => $nodes], 'maxProperties' => UiTreeParser::MAX_NODES],
                'data' => ['type' => 'object'],
                'actions' => ['type' => 'object', 'additionalProperties' => [
                    'type' => 'object',
                    'properties' => ['kind' => ['enum' => $this->actions->kinds()]],
                    'required' => ['kind'],
                ]],
            ],
            'required' => ['version', 'root', 'nodes'],
            'additionalProperties' => false,
        ];
    }

    /** The components as compact text, one block each — what a prompt carries. */
    public function describe(): string
    {
        $blocks = [];
        foreach ($this->components() as $c) {
            $item = $this->catalog->forVisitor()[$c['name']];
            $lines = [sprintf('%s — %s', $c['name'], $c['summary'])];
            foreach ($item->contract?->props ?? [] as $prop) {
                $lines[] = '  ' . self::propLine($prop);
            }
            $lines[] = $c['slots'] === [] ? '  holds no children' : '  children into slots: ' . implode(', ', $c['slots']) . ' (default: ' . $c['slots'][0] . ')';
            if ($c['example'] !== null) {
                $lines[] = '  e.g. props ' . json_encode($c['example'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $blocks[] = implode("\n", $lines);
        }

        return implode("\n\n", $blocks);
    }

    private static function propLine(UiProp $prop): string
    {
        $type = $prop->type->value;
        if ($prop->items !== null && $prop->items->properties !== []) {
            $type = 'list of {' . implode(', ', array_map(static fn (UiProp $p): string => $p->name . ($p->required ? '' : '?'), $prop->items->properties)) . '}';
        }

        return sprintf(
            '%s%s: %s%s%s%s',
            $prop->name,
            $prop->required ? '' : '?',
            $type,
            $prop->values !== [] ? ' one of ' . implode('|', array_map('strval', $prop->values)) : '',
            $prop->default !== null ? ' (default ' . json_encode($prop->default) . ')' : '',
            $prop->description !== '' ? ' — ' . $prop->description : '',
        );
    }
}
