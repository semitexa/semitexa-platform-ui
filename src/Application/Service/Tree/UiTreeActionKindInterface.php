<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Tree;

use Semitexa\PlatformUi\Domain\Model\Tree\UiTreeError;

/**
 * One kind of tree action (#[AsUiTreeAction]): what its arguments must be for
 * this visitor, and what a prop that refers to it becomes when drawn.
 */
interface UiTreeActionKindInterface
{
    /**
     * @param array<string, mixed> $action the action as the tree declares it, `kind` included
     * @return list<UiTreeError> what is wrong with it for this visitor; [] when it may run
     */
    public function check(array $action, string $path): array;

    /**
     * What a prop referring to the action is drawn with — for a link, its
     * same-site path.
     *
     * @param array<string, mixed> $action
     */
    public function propValue(array $action): string;

    /** One line for an agent: its shape and what it does ('{"kind": "navigate", "to": "/path"} — …'). */
    public function describe(): string;
}
