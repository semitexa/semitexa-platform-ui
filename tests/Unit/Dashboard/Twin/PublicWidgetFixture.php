<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Dashboard\Twin;

use Semitexa\PlatformUi\Application\Service\Dashboard\UiDashboardWidgetInterface;
use Semitexa\PlatformUi\Attribute\AsDashboardWidget;
use Semitexa\PlatformUi\Domain\Model\Dashboard\UiWidget;

/** Named like DashboardTest's PublicWidgetFixture, in another namespace: the same widget id. */
#[AsDashboardWidget(dashboard: 'admin', order: 50)]
final class PublicWidgetFixture implements UiDashboardWidgetInterface
{
    public function widget(): UiWidget { return UiWidget::stat('Twin', '1'); }
}
