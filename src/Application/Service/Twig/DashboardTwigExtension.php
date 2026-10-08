<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Twig;

use Semitexa\PlatformUi\Application\Service\Dashboard\UiDashboards;
use Semitexa\Ssr\Application\Service\Extension\TwigExtensionRegistry;
use Semitexa\Ssr\Attribute\AsTwigExtension;

#[AsTwigExtension]
final class DashboardTwigExtension
{
    public function registerFunctions(): void
    {
        /**
         * ui_dashboard(name) — the widgets of a dashboard the visitor may see,
         * computed now: [{id, wide, widget: UiWidget|null}]. platform.dashboard
         * renders them; call it directly only for a layout of your own.
         */
        TwigExtensionRegistry::registerFunction('ui_dashboard', UiDashboards::render(...));

        /**
         * ui_dashboard_entries(name) — the widgets of a dashboard the visitor
         * may see, not computed: [{id, wide}]. ui_dashboard_widget(name, id) —
         * one of them, computed now (UiWidget|null). platform.dashboard lays
         * out the first and draws each widget lazily with the second.
         */
        TwigExtensionRegistry::registerFunction('ui_dashboard_entries', UiDashboards::entries(...));
        TwigExtensionRegistry::registerFunction('ui_dashboard_widget', UiDashboards::widget(...));
    }
}
