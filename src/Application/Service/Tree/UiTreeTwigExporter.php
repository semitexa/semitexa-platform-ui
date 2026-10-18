<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Tree;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\PlatformUi\Application\Service\Workbench\TwigLiteral;
use Semitexa\PlatformUi\Domain\Model\Tree\UiTree;

/**
 * Writes a UI tree out as the Twig a developer would have written by hand:
 * one `component()` or `primitive()` call per node, children concatenated
 * into the slot they target. It walks the tree exactly as
 * {@see UiTreeRenderer} draws it — same catalog, same default slot, bindings
 * and actions resolved to the same values — so the exported template renders
 * the HTML the preview showed. That parity is the contract of this class.
 *
 * The result is a template, not a format: there is no stored composition to
 * keep in sync, and once written the file is the developer's to edit.
 * Output is deterministic — the same tree always gives the same text.
 */
#[AsService]
final class UiTreeTwigExporter
{
    #[InjectAsReadonly]
    protected UiTreeValidator $validator;

    #[InjectAsReadonly]
    protected UiAgentCatalog $catalog;

    #[InjectAsReadonly]
    protected UiTreeActions $actions;

    /**
     * Check a tree document and export it.
     *
     * @return array{twig: ?string, errors: list<\Semitexa\PlatformUi\Domain\Model\Tree\UiTreeError>}
     */
    public function export(mixed $document): array
    {
        $checked = $this->validator->check($document);
        if ($checked['tree'] === null) {
            return ['twig' => null, 'errors' => $checked['errors']];
        }

        return ['twig' => $this->write($checked['tree']), 'errors' => []];
    }

    /** Export a tree the validator already passed. */
    public function write(UiTree $tree): string
    {
        return "<div data-ui-tree>{{ " . $this->node($tree, $tree->root, '') . " }}</div>";
    }

    private function node(UiTree $tree, string $id, string $indent): string
    {
        $node = $tree->nodes[$id];
        $item = $this->catalog->open()[$node->type] ?? null;
        if ($item === null) {
            return "''"; // unreachable for a validated tree
        }

        $props = [];
        foreach ($node->props as $name => $value) {
            $pointer = UiTree::bindingOf($value);
            $action = UiTreeValidator::actionOf($value);
            $props[$name] = match (true) {
                $pointer !== null => $tree->resolve($pointer)['value'],
                $action !== null => $this->actions->propValue($tree, $action),
                default => $value,
            };
        }

        if ($item->kind === 'primitive') {
            return 'primitive(' . TwigLiteral::export($node->type) . ($props === [] ? '' : ', ' . TwigLiteral::export($props)) . ')';
        }

        $slots = [];
        $default = UiAgentCatalog::slotsOf($item)[0] ?? null;
        $inner = $indent . '    ';
        foreach ($node->children as $child) {
            $slot = $tree->nodes[$child]->slot ?? $default;
            if ($slot !== null) {
                $slots[$slot][] = $this->node($tree, $child, $inner . '    ');
            }
        }

        $call = 'component(' . TwigLiteral::export($node->type);
        if ($props !== [] || $slots !== []) {
            $call .= ', ' . TwigLiteral::export($props);
        }
        if ($slots === []) {
            return $call . ')';
        }

        // Slots are rendered raw, so the children's markup is concatenated in
        // as it comes out of their own calls, the way the renderer joins them.
        $lines = [];
        foreach ($slots as $slot => $calls) {
            $lines[] = $inner . self::key($slot) . ': ' . implode("\n" . $inner . '    ~ ', $calls) . ',';
        }

        return $call . ", {\n" . implode("\n", $lines) . "\n" . $indent . '})';
    }

    private static function key(string $slot): string
    {
        return preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $slot) === 1 ? $slot : TwigLiteral::export($slot);
    }
}
