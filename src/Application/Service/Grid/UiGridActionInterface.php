<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Grid;

use Semitexa\PlatformUi\Domain\Model\Grid\UiGridActionContext;
use Semitexa\PlatformUi\Domain\Model\Grid\UiGridActionResult;

/**
 * The server side of a grid's actions (#[AsGridAction]). It runs after the
 * grid has checked that the action is one it was rendered with and that the
 * ids fit its scope (one row, a selection, or none for a header action); it
 * must still check the visitor may run it, and which of the ids they may touch.
 */
interface UiGridActionInterface
{
    public function name(): string;

    public function handle(UiGridActionContext $context): UiGridActionResult;
}
