<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\Ssr\Attribute\AsComponent;

/**
 * platform.dashboard-widget — one widget of a dashboard, computed when it is
 * drawn: the body of one platform.dashboard card.
 *
 * Drawn inline: the Playground widgets compute in a few milliseconds, and a
 * skeleton that flashes for a few milliseconds is worse than none. A dashboard
 * whose widgets are slow can defer its own widget component
 * (#[WithTransport(Sse, deferred: true)]): deferred components render
 * concurrently, as the visitor, and arrive ~30 ms after the page (measured
 * 2026-10-06).
 */
#[AsComponent(
    name: 'platform.dashboard-widget',
    template: '@platform-ui/components/runtime/dashboard-widget.html.twig',
    cacheable: false,
)]
#[AsUiContract(
    summary: 'One widget of a dashboard, computed when drawn: the body of a platform.dashboard card.',
    props: [
        new UiProp('dashboard', required: true, description: 'The dashboard the widget is registered under.'),
        new UiProp('widget', required: true, description: 'The widget id platform.dashboard laid out.'),
    ],
    previewSafe: false,
)]
final class DashboardWidgetComponent
{
}
