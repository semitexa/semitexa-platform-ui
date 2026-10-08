<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Tree;

/**
 * A screen an agent composed (ep-platform-ai-ui): a flat, id-referenced tree
 * of catalog components, its data model, and its named actions — the shape of
 * A2UI v0.9, Semitexa's own format (see docs `rendering/ai-ui.md`).
 *
 *     {
 *       "version": "semitexa.ui-tree/v1",
 *       "root": "page",
 *       "nodes": {
 *         "page":  {"type": "platform.card", "props": {"title": "Orders"}, "children": ["open"]},
 *         "open":  {"type": "platform.stat", "props": {"label": "Open", "value": {"$data": "/orders/open"}}}
 *       },
 *       "data": {"orders": {"open": 12}},
 *       "actions": {"newOrder": {"kind": "navigate", "to": "/orders?create"}}
 *     }
 *
 * Only {@see UiTreeParser} makes one, and only from a document whose shape is
 * sound: every child exists and has one parent, there is no cycle, every node
 * is reachable from the root.
 */
final readonly class UiTree
{
    public const VERSION = 'semitexa.ui-tree/v1';

    /**
     * @param array<string, UiTreeNode> $nodes
     * @param array<string, mixed> $data
     * @param array<string, array<string, mixed>> $actions
     */
    public function __construct(
        public string $root,
        public array $nodes,
        public array $data,
        public array $actions,
    ) {
    }

    public function node(string $id): ?UiTreeNode
    {
        return $this->nodes[$id] ?? null;
    }

    /**
     * Resolve a JSON Pointer into the data model.
     *
     * @return array{found: bool, value: mixed}
     */
    public function resolve(string $pointer): array
    {
        if ($pointer === '') {
            return ['found' => true, 'value' => $this->data];
        }
        $value = $this->data;
        foreach (array_slice(explode('/', $pointer), 1) as $token) {
            $token = str_replace(['~1', '~0'], ['/', '~'], $token);
            if (!is_array($value) || !array_key_exists($token, $value)) {
                return ['found' => false, 'value' => null];
            }
            $value = $value[$token];
        }

        return ['found' => true, 'value' => $value];
    }

    /** Whether a prop value is a binding to the data model, and to which pointer. */
    public static function bindingOf(mixed $value): ?string
    {
        return is_array($value) && count($value) === 1 && is_string($value['$data'] ?? null) ? $value['$data'] : null;
    }
}
