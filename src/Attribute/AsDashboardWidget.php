<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Attribute;

use Attribute;
use Semitexa\Core\Attribute\Capability;

/**
 * Puts a widget on a dashboard.
 *
 *     #[AsService]
 *     #[ExecutionScoped]              // when it injects the visitor (auth, tenant)
 *     #[AsDashboardWidget(dashboard: 'admin', order: 10, permission: 'orders.read')]
 *     final class OpenOrders implements UiDashboardWidgetInterface
 *     {
 *         public function widget(): UiWidget { return UiWidget::stat('Open orders', '12'); }
 *     }
 *
 *     {{ component('platform.dashboard', {name: 'admin'}) }}
 *
 * A widget with a permission is shown only to a visitor who holds it, and is
 * not even computed for anyone else. `wide` spans two columns.
 */
#[Capability(
    id: 'ui.dashboard-widget',
    summary: 'A dashboard widget - a stat with a trend, a list of recent records, or a small SVG chart - registered by attribute, shown only with its permission, laid out by platform.dashboard.',
    useWhen: 'A landing page summarises the application: counts, trends, what changed recently.',
    avoidWhen: 'The page is about one record or one list - use a CRUD screen or a page of its own.',
    replaces: [
        'a dashboard page whose handler gathers every number and checks every permission by hand',
        'a JavaScript chart library for a sparkline',
    ],
    seeAlso: 'crud.screen',
)]
#[Attribute(Attribute::TARGET_CLASS)]
final class AsDashboardWidget
{
    public function __construct(
        public string $dashboard,
        public int $order = 100,
        public ?string $permission = null,
        public bool $wide = false,
    ) {
        if (preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/', $dashboard) !== 1) {
            throw new \InvalidArgumentException(sprintf('A dashboard name is a lowercase word, not "%s".', $dashboard));
        }
    }
}
