<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Tree;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\PlatformUi\Application\Service\Primitive\PrimitiveRenderer;
use Semitexa\PlatformUi\Domain\Model\Tree\UiTree;
use Semitexa\Ssr\Application\Service\Component\ComponentRenderer;

/**
 * Draws a UI tree (tk-ai-render): each node through the renderer a template
 * would use — a component through ComponentRenderer, a primitive through
 * PrimitiveRenderer — with its data bindings resolved and its children drawn
 * into its slots. The HTML is what hand-written Twig composing the same
 * components would give.
 *
 * It draws only a tree the validator passed for this visitor: {@see render()}
 * checks first, and a tree that fails is never drawn.
 */
#[AsService]
final class UiTreeRenderer
{
    #[InjectAsReadonly]
    protected UiTreeValidator $validator;

    #[InjectAsReadonly]
    protected UiAgentCatalog $catalog;

    #[InjectAsReadonly]
    protected UiTreeActions $actions;

    private ?PrimitiveRenderer $primitives = null;

    /**
     * Check a tree document for this visitor and draw it.
     *
     * @return array{html: ?string, errors: list<\Semitexa\PlatformUi\Domain\Model\Tree\UiTreeError>}
     */
    public function render(mixed $document): array
    {
        $checked = $this->validator->check($document);
        if ($checked['tree'] === null) {
            return ['html' => null, 'errors' => $checked['errors']];
        }

        return ['html' => $this->draw($checked['tree']), 'errors' => []];
    }

    /** Draw a tree the validator already passed. */
    public function draw(UiTree $tree): string
    {
        return '<div data-ui-tree>' . $this->node($tree, $tree->root) . '</div>';
    }

    private function node(UiTree $tree, string $id): string
    {
        $node = $tree->nodes[$id];
        $item = $this->catalog->open()[$node->type] ?? null;
        if ($item === null) {
            return ''; // unreachable for a validated tree
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
            return ($this->primitives ??= new PrimitiveRenderer())->render($node->type, $props);
        }

        $slots = [];
        $default = UiAgentCatalog::slotsOf($item)[0] ?? null;
        foreach ($node->children as $child) {
            $slot = $tree->nodes[$child]->slot ?? $default;
            if ($slot !== null) {
                $slots[$slot] = ($slots[$slot] ?? '') . $this->node($tree, $child);
            }
        }

        return ComponentRenderer::render($node->type, $props, $slots);
    }
}
