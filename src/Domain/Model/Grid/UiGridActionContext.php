<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Grid;

/**
 * One grid action, as asked: which action (one the grid was rendered with),
 * in which scope, on which row ids (none for a header action), and the grid's
 * signed props (a screen id, say — values the browser cannot change).
 */
final readonly class UiGridActionContext
{
    /**
     * @param list<string>         $ids
     * @param array<string, mixed> $props
     */
    public function __construct(
        public string $gridInstanceId,
        public UiGridAction $action,
        public string $scope,
        public array $ids,
        public array $props = [],
    ) {}
}
