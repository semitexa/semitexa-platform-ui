<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Dashboard;

/**
 * A widget that knows the permission it needs (a CRUD screen's widget needs
 * that screen's read permission), so #[AsDashboardWidget] does not repeat it.
 * Asked before the widget is resolved; null means "signed in".
 */
interface UiWidgetPermissionInterface
{
    public static function requiredPermission(): ?string;
}
