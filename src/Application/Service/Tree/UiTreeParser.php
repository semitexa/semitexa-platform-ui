<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Tree;

use Semitexa\Core\Attribute\AsService;
use Semitexa\PlatformUi\Domain\Model\Tree\UiTree;
use Semitexa\PlatformUi\Domain\Model\Tree\UiTreeError;
use Semitexa\PlatformUi\Domain\Model\Tree\UiTreeNode;

/**
 * Reads a UI tree document (tk-ai-tree) and says everything wrong with its
 * SHAPE at once — a model repairs a whole answer in one turn, not one fault
 * per round trip. What the shape cannot know (whether a component exists, its
 * props, who may see it) is {@see UiTreeValidator}'s.
 */
#[AsService]
final class UiTreeParser
{
    public const MAX_NODES = 500;
    public const MAX_DEPTH = 32;

    /** Node ids, slot and action names: short, lowercase-led words. */
    private const ID = '/\A[A-Za-z][A-Za-z0-9_-]{0,63}\z/';

    /** A catalog name: `platform.card`, `ui-playground.counter`. */
    private const TYPE = '/\A[a-z][a-z0-9-]*(\.[a-z0-9][a-z0-9-]*)+\z/';

    /** @return array{tree: ?UiTree, errors: list<UiTreeError>} */
    public function parse(mixed $document): array
    {
        if (is_string($document)) {
            try {
                $document = json_decode($document, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                return ['tree' => null, 'errors' => [new UiTreeError('tree.json', '', 'The tree is not valid JSON: ' . $e->getMessage() . '.', 'a JSON object')]];
            }
        }
        if (!is_array($document) || ($document !== [] && array_is_list($document))) {
            return ['tree' => null, 'errors' => [new UiTreeError('tree.shape', '', 'A tree is a JSON object.', 'an object with version, root, nodes', UiTreeError::describe($document))]];
        }

        $errors = [];
        foreach (array_keys($document) as $key) {
            if (!in_array($key, ['version', 'root', 'nodes', 'data', 'actions'], true)) {
                $errors[] = new UiTreeError('tree.unknown_field', '/' . $key, sprintf('"%s" is not part of a tree.', $key), 'version, root, nodes, data, actions', null, 'Remove it.');
            }
        }
        if (($document['version'] ?? null) !== UiTree::VERSION) {
            $errors[] = new UiTreeError('tree.version', '/version', 'The tree names no version this server reads.', UiTree::VERSION, UiTreeError::describe($document['version'] ?? null));
        }

        $rawNodes = $document['nodes'] ?? null;
        if (!is_array($rawNodes) || $rawNodes === [] || array_is_list($rawNodes)) {
            $errors[] = new UiTreeError('tree.nodes', '/nodes', 'A tree has nodes: an object of node id => node.', '{"<id>": {"type": "...", "props": {}, "children": []}}', UiTreeError::describe($rawNodes));

            return ['tree' => null, 'errors' => $errors];
        }
        if (count($rawNodes) > self::MAX_NODES) {
            $errors[] = new UiTreeError('tree.too_large', '/nodes', sprintf('A tree has at most %d nodes.', self::MAX_NODES), '<= ' . self::MAX_NODES, (string) count($rawNodes), 'Split the screen, or use a grid for a list of records.');

            return ['tree' => null, 'errors' => $errors];
        }

        $nodes = [];
        foreach ($rawNodes as $id => $raw) {
            $node = $this->node((string) $id, $raw, $errors);
            if ($node !== null) {
                $nodes[(string) $id] = $node;
            }
        }

        $root = $document['root'] ?? null;
        if (!is_string($root) || !isset($rawNodes[$root])) {
            $errors[] = new UiTreeError('tree.root', '/root', 'The root names no node of the tree.', 'one of: ' . implode(', ', array_slice(array_map('strval', array_keys($rawNodes)), 0, 10)), UiTreeError::describe($root));
        } else {
            $this->checkStructure($root, $nodes, $errors);
        }

        $data = $document['data'] ?? [];
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            $errors[] = new UiTreeError('tree.data', '/data', 'The data model is a JSON object.', 'an object', UiTreeError::describe($data));
            $data = [];
        }

        $actions = $this->actions($document['actions'] ?? [], $errors);

        if ($errors !== [] || !is_string($root)) {
            return ['tree' => null, 'errors' => $errors];
        }
        $tree = new UiTree($root, $nodes, $data, $actions);
        $this->checkBindings($tree, $errors);

        return $errors === [] ? ['tree' => $tree, 'errors' => []] : ['tree' => null, 'errors' => $errors];
    }

    /** @param list<UiTreeError> $errors */
    private function node(string $id, mixed $raw, array &$errors): ?UiTreeNode
    {
        $path = '/nodes/' . self::escape($id);
        if (preg_match(self::ID, $id) !== 1) {
            $errors[] = new UiTreeError('tree.node_id', $path, 'A node id is a short word: a letter, then letters, digits, - or _.', '[A-Za-z][A-Za-z0-9_-]{0,63}', UiTreeError::describe($id));
        }
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            $errors[] = new UiTreeError('tree.node', $path, 'A node is an object.', '{"type": "...", "props": {}, "children": []}', UiTreeError::describe($raw));

            return null;
        }
        foreach (array_keys($raw) as $key) {
            if (!in_array($key, ['type', 'props', 'children', 'slot'], true)) {
                $errors[] = new UiTreeError('tree.node_field', $path . '/' . self::escape((string) $key), sprintf('"%s" is not part of a node.', $key), 'type, props, children, slot', null, 'Put component settings in props.');
            }
        }
        $type = $raw['type'] ?? null;
        if (!is_string($type) || preg_match(self::TYPE, $type) !== 1) {
            $errors[] = new UiTreeError('tree.node_type', $path . '/type', 'A node names its component by catalog name.', 'a name like "platform.card"', UiTreeError::describe($type));
        }
        $props = $raw['props'] ?? [];
        if (!is_array($props) || ($props !== [] && array_is_list($props))) {
            $errors[] = new UiTreeError('tree.node_props', $path . '/props', 'Props are an object of name => value.', 'an object', UiTreeError::describe($props));
            $props = [];
        }
        $children = $raw['children'] ?? [];
        if (!is_array($children) || !array_is_list($children) || array_filter($children, static fn (mixed $c): bool => !is_string($c)) !== []) {
            $errors[] = new UiTreeError('tree.node_children', $path . '/children', 'Children are a list of node ids.', '["<id>", ...]', UiTreeError::describe($children));
            $children = [];
        }
        $slot = $raw['slot'] ?? null;
        if ($slot !== null && (!is_string($slot) || preg_match(self::ID, $slot) !== 1)) {
            $errors[] = new UiTreeError('tree.node_slot', $path . '/slot', 'A slot is the name of one of the parent\'s slots.', 'a slot name, or none for the parent\'s content', UiTreeError::describe($slot));
            $slot = null;
        }

        /** @var array<string, mixed> $props */
        /** @var list<string> $children */
        return new UiTreeNode($id, is_string($type) ? $type : '', $props, $children, $slot);
    }

    /**
     * Every child exists and has one parent; there is no cycle; the tree is
     * at most MAX_DEPTH deep; every node hangs from the root.
     *
     * @param array<string, UiTreeNode> $nodes
     * @param list<UiTreeError> $errors
     */
    private function checkStructure(string $root, array $nodes, array &$errors): void
    {
        $parentOf = [];
        foreach ($nodes as $id => $node) {
            foreach ($node->children as $index => $child) {
                $path = '/nodes/' . self::escape($id) . '/children/' . $index;
                if (!isset($nodes[$child])) {
                    $errors[] = new UiTreeError('tree.child_unknown', $path, sprintf('"%s" names no node.', $child), 'an id from nodes', $child, 'Add the node, or remove it from children.');
                } elseif ($child === $root) {
                    $errors[] = new UiTreeError('tree.child_root', $path, 'The root cannot be a child.', 'a node other than the root', $child);
                } elseif (isset($parentOf[$child])) {
                    $errors[] = new UiTreeError('tree.child_twice', $path, sprintf('"%s" already belongs to "%s": a node has one parent.', $child, $parentOf[$child]), 'a node of its own', $child, 'Give the second place its own node with a new id.');
                } else {
                    $parentOf[$child] = $id;
                }
            }
        }

        // Depth and reachability from the root; a cycle is a node seen twice on one path.
        $reached = [];
        $walk = function (string $id, int $depth, array $onPath) use (&$walk, &$reached, $nodes, &$errors): void {
            if (isset($onPath[$id])) {
                $errors[] = new UiTreeError('tree.cycle', '/nodes/' . self::escape($id), sprintf('"%s" contains itself.', $id), 'a tree, not a loop');

                return;
            }
            if ($depth > self::MAX_DEPTH) {
                $errors[] = new UiTreeError('tree.too_deep', '/nodes/' . self::escape($id), sprintf('A tree is at most %d levels deep.', self::MAX_DEPTH), '<= ' . self::MAX_DEPTH, (string) $depth);

                return;
            }
            $reached[$id] = true;
            $onPath[$id] = true;
            foreach ($nodes[$id]->children ?? [] as $child) {
                if (isset($nodes[$child]) && !isset($reached[$child])) {
                    $walk($child, $depth + 1, $onPath);
                } elseif (isset($onPath[$child])) {
                    $errors[] = new UiTreeError('tree.cycle', '/nodes/' . self::escape($child), sprintf('"%s" contains itself.', $child), 'a tree, not a loop');
                }
            }
        };
        if (isset($nodes[$root])) {
            $walk($root, 1, []);
        }
        foreach (array_keys($nodes) as $id) {
            if (!isset($reached[$id])) {
                $errors[] = isset($parentOf[$id])
                    ? new UiTreeError('tree.unreachable', '/nodes/' . self::escape($id), sprintf('"%s" hangs from "%s", which the root does not reach (a detached branch or a loop).', $id, $parentOf[$id]), 'a node the root reaches', null, 'Attach the branch to the root\'s tree, or remove it.')
                    : new UiTreeError('tree.unreachable', '/nodes/' . self::escape($id), sprintf('"%s" hangs from nothing: it is not the root and no node lists it.', $id), 'a child of some node', null, 'Add it to a parent\'s children, or remove it.');
            }
        }
    }

    /**
     * @param list<UiTreeError> $errors
     * @return array<string, array<string, mixed>>
     */
    private function actions(mixed $raw, array &$errors): array
    {
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            $errors[] = new UiTreeError('tree.actions', '/actions', 'Actions are an object of name => action.', 'an object', UiTreeError::describe($raw));

            return [];
        }
        $actions = [];
        foreach ($raw as $name => $action) {
            $path = '/actions/' . self::escape((string) $name);
            if (preg_match(self::ID, (string) $name) !== 1) {
                $errors[] = new UiTreeError('tree.action_name', $path, 'An action name is a short word.', '[A-Za-z][A-Za-z0-9_-]{0,63}', UiTreeError::describe($name));
                continue;
            }
            // Which kinds exist is the validator's to say (UiTreeActions); the
            // shape only needs one named.
            if (!is_array($action) || !is_string($action['kind'] ?? null) || preg_match('/\A[a-z][a-z0-9-]{0,31}\z/', $action['kind']) !== 1) {
                $errors[] = new UiTreeError('tree.action_kind', $path . '/kind', 'An action names its kind.', '{"kind": "navigate", …}', UiTreeError::describe(is_array($action) ? ($action['kind'] ?? null) : $action));
                continue;
            }
            $actions[(string) $name] = $action;
        }

        return $actions;
    }

    /**
     * Every `{"$data": pointer}` prop is a valid JSON Pointer to something in
     * the data model.
     *
     * @param list<UiTreeError> $errors
     */
    private function checkBindings(UiTree $tree, array &$errors): void
    {
        foreach ($tree->nodes as $id => $node) {
            foreach ($node->props as $prop => $value) {
                if (is_array($value) && array_key_exists('$data', $value)) {
                    $path = '/nodes/' . self::escape($id) . '/props/' . self::escape((string) $prop);
                    $pointer = UiTree::bindingOf($value);
                    if ($pointer === null || ($pointer !== '' && !str_starts_with($pointer, '/'))) {
                        $errors[] = new UiTreeError('tree.data_pointer', $path, 'A binding is {"$data": "<JSON Pointer>"} and nothing else.', '{"$data": "/orders/open"}', UiTreeError::describe($value));
                    } elseif (!$tree->resolve($pointer)['found']) {
                        $errors[] = new UiTreeError('tree.data_missing', $path, sprintf('The data model has nothing at "%s".', $pointer), 'a pointer into data', $pointer, 'Add the value to data, or bind to one that is there.');
                    }
                }
            }
        }
    }

    /** A JSON Pointer token. */
    public static function escape(string $token): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $token);
    }
}
