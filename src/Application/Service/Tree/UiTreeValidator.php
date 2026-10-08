<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Tree;

use InvalidArgumentException;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\PlatformUi\Domain\Model\Contract\UiCatalogItem;
use Semitexa\PlatformUi\Domain\Model\Tree\UiTree;
use Semitexa\PlatformUi\Domain\Model\Tree\UiTreeError;

/**
 * Whether a UI tree is one THIS visitor may be shown (tk-ai-validate) — the
 * same catalog and the same permissions a person composing the screen gets:
 *
 * - every node names a component open to agents, and one the visitor may use;
 * - its props pass that component's contract (bindings checked by the value
 *   they bind to): no prop it does not declare, none it requires missing, each
 *   of its declared type and values;
 * - its children go into slots it declares;
 * - every `{"$action": name}` names one of the tree's actions.
 *
 * Every fault is reported, each with its path and what was expected, so a model
 * repairs the whole answer in one turn. A tree that fails is never rendered.
 */
#[AsService]
final class UiTreeValidator
{
    #[InjectAsReadonly]
    protected UiAgentCatalog $catalog;

    #[InjectAsReadonly]
    protected UiTreeParser $parser;

    #[InjectAsReadonly]
    protected UiTreeActions $actions;

    /**
     * Parse and validate in one: the tree, or every reason it is not one.
     *
     * @return array{tree: ?UiTree, errors: list<UiTreeError>}
     */
    public function check(mixed $document): array
    {
        $parsed = $this->parser->parse($document);
        if ($parsed['tree'] === null) {
            return $parsed;
        }
        $errors = $this->validate($parsed['tree']);

        return ['tree' => $errors === [] ? $parsed['tree'] : null, 'errors' => $errors];
    }

    /** @return list<UiTreeError> */
    public function validate(UiTree $tree): array
    {
        $open = $this->catalog->open();
        $errors = [];
        // An action that fails its own check is reported once, there; a prop
        // referring to it is not checked against the value it cannot produce,
        // or one fault (an unknown screen) came back as two.
        $actionErrors = $this->actions->check($tree);
        $broken = [];
        foreach ($tree->actions as $name => $_) {
            $prefix = '/actions/' . UiTreeParser::escape((string) $name);
            foreach ($actionErrors as $error) {
                if ($error->path === $prefix || str_starts_with($error->path, $prefix . '/')) {
                    $broken[(string) $name] = true;
                    break;
                }
            }
        }
        foreach ($tree->nodes as $id => $node) {
            $path = '/nodes/' . UiTreeParser::escape($id);
            $item = $open[$node->type] ?? null;
            if ($item === null) {
                $errors[] = new UiTreeError(
                    'tree.component_unknown',
                    $path . '/type',
                    sprintf('"%s" is not a component an agent may use.', $node->type),
                    'one of the catalog\'s agent components',
                    $node->type,
                    self::closest($node->type, array_keys($this->catalog->forVisitor())),
                );
                continue;
            }
            if (!UiAgentCatalog::allowed($item)) {
                $errors[] = new UiTreeError('tree.component_forbidden', $path . '/type', sprintf('This visitor may not be shown "%s".', $node->type), 'a component this visitor may use', $node->type, 'Leave it out of this screen.');
                continue;
            }
            array_push($errors, ...$this->props($tree, $item, $path, $node->props, $broken));
            array_push($errors, ...$this->children($tree, $item, $id, $path));
        }
        array_push($errors, ...$actionErrors);

        return $errors;
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, true> $broken actions that failed their own check
     * @return list<UiTreeError>
     */
    private function props(UiTree $tree, UiCatalogItem $item, string $path, array $props, array $broken = []): array
    {
        $declared = $item->contract?->props ?? [];
        $errors = [];
        foreach ($props as $name => $value) {
            $propPath = $path . '/props/' . UiTreeParser::escape((string) $name);
            $prop = $declared[$name] ?? null;
            if ($prop === null) {
                $errors[] = new UiTreeError('tree.prop_unknown', $propPath, sprintf('%s has no prop "%s".', $item->name(), $name), $declared === [] ? 'no props' : implode(', ', array_keys($declared)), (string) $name, self::closest((string) $name, array_keys($declared)));
                continue;
            }
            $action = self::actionOf($value);
            if ($action !== null && !isset($tree->actions[$action])) {
                $errors[] = new UiTreeError('tree.action_unknown', $propPath, sprintf('"%s" names no action of the tree.', $action), $tree->actions === [] ? 'an action declared under actions' : implode(', ', array_keys($tree->actions)), $action, 'Declare it under actions, or remove the reference.');
                continue;
            }
            if ($action !== null && isset($broken[$action])) {
                continue;
            }
            // An action reference is drawn as the value its kind resolves to
            // (a navigate is its path): that value must fit the prop like any
            // other, or a reference would slip a path into an enum, a boolean
            // or a list the contract never allowed.
            $pointer = UiTree::bindingOf($value);
            $checked = match (true) {
                $action !== null => $this->actions->propValue($tree, $action),
                $pointer !== null => $tree->resolve($pointer)['value'],
                default => $value,
            };
            try {
                $prop->validate($checked, $name);
            } catch (InvalidArgumentException $e) {
                $errors[] = new UiTreeError(
                    'tree.prop_invalid',
                    $propPath,
                    match (true) {
                        $action !== null => sprintf('The action "%s" does not fit: ', $action),
                        $pointer !== null => sprintf('The data at "%s" does not fit: ', $pointer),
                        default => '',
                    } . $e->getMessage(),
                    (string) json_encode($prop->schema(), JSON_UNESCAPED_SLASHES),
                    UiTreeError::describe($checked),
                );
            }
        }
        foreach ($declared as $name => $prop) {
            if ($prop->required && !array_key_exists($name, $props)) {
                $errors[] = new UiTreeError('tree.prop_required', $path . '/props', sprintf('%s requires "%s".', $item->name(), $name), (string) json_encode($prop->schema(), JSON_UNESCAPED_SLASHES), null, $prop->description !== '' ? $prop->description : null);
            }
        }

        return $errors;
    }

    /** @return list<UiTreeError> */
    private function children(UiTree $tree, UiCatalogItem $item, string $id, string $path): array
    {
        $node = $tree->nodes[$id];
        if ($node->children === []) {
            return [];
        }
        $slots = UiAgentCatalog::slotsOf($item);
        if ($slots === []) {
            return [new UiTreeError('tree.children_not_allowed', $path . '/children', sprintf('%s holds no other components.', $item->name()), 'no children', implode(', ', $node->children), 'Move the children to a component with slots, such as platform.card.')];
        }
        $errors = [];
        foreach ($node->children as $child) {
            $slot = $tree->nodes[$child]->slot ?? null;
            if ($slot !== null && !in_array($slot, $slots, true)) {
                $errors[] = new UiTreeError('tree.slot_unknown', '/nodes/' . UiTreeParser::escape($child) . '/slot', sprintf('%s has no slot "%s".', $item->name(), $slot), implode(', ', $slots), $slot, sprintf('Leave the slot out to use "%s".', $slots[0]));
            }
        }

        return $errors;
    }

    /** Whether a prop value is a reference to one of the tree's actions, and to which. */
    public static function actionOf(mixed $value): ?string
    {
        return is_array($value) && count($value) === 1 && is_string($value['$action'] ?? null) ? $value['$action'] : null;
    }

    /** "Did you mean …" — the nearest name, when one is near. @param list<string> $names */
    private static function closest(string $name, array $names): ?string
    {
        $best = null;
        $distance = PHP_INT_MAX;
        foreach ($names as $candidate) {
            $d = levenshtein($name, $candidate);
            if ($d < $distance) {
                [$best, $distance] = [$candidate, $d];
            }
        }

        return $best !== null && $distance <= max(3, intdiv(strlen($name), 3)) ? sprintf('Did you mean "%s"?', $best) : null;
    }
}
