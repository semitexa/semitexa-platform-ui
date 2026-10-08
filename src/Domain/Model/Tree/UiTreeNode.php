<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Tree;

/**
 * One node of a UI tree: a catalog entry (`type`) with its props, drawn into
 * its parent's `slot` (or its default content), with its children in order.
 * A prop may be a binding to the tree's data model: `{"$data": "/json/pointer"}`.
 */
final readonly class UiTreeNode
{
    /**
     * @param array<string, mixed> $props
     * @param list<string> $children
     */
    public function __construct(
        public string $id,
        public string $type,
        public array $props,
        public array $children,
        public ?string $slot,
    ) {
    }
}
