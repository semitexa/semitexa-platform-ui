<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Dashboard;

use Semitexa\PlatformUi\Domain\Model\Dashboard\UiWidget;

/** A dashboard widget (#[AsDashboardWidget]): asked once per page view. */
interface UiDashboardWidgetInterface
{
    public function widget(): UiWidget;
}
