<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin;

use Semitexa\PlatformUi\Application\Service\Dashboard\UiDashboards;
use Semitexa\PlatformUi\Application\Service\Island\UiIslandInterface;
use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\Ssr\Attribute\AsComponent;

/**
 * platform.dashboard — the widgets of one dashboard (#[AsDashboardWidget]),
 * those the visitor may see, in order, each in a card: a stat with its
 * sparkline, a list, or a chart. The grid reflows by its own width.
 *
 *     {{ component('platform.dashboard', {name: 'admin'}) }}
 *
 * An island: it watches what the visitor's widgets read
 * (UiWidgetWatchesInterface), so a write to that redraws it on every open page.
 */
#[AsComponent(
    name: 'platform.dashboard',
    template: '@platform-ui/components/runtime/dashboard.html.twig',
    // Per visitor (permissions) and per moment (the numbers): never cached.
    cacheable: false,
)]
#[AsUiContract(
    summary: 'A dashboard: every #[AsDashboardWidget] of one name the visitor may see, laid out as cards.',
    props: [
        new UiProp('name', required: true, description: 'The dashboard name the widgets were registered under.'),
        new UiProp('emptyMessage', default: 'Nothing to show here yet.'),
    ],
    previewSafe: false,
)]
final class DashboardComponent implements UiIslandInterface
{
    public function watches(array $props): array
    {
        $name = $props['name'] ?? null;

        return is_string($name) && $name !== '' ? UiDashboards::watches($name) : [];
    }
}
