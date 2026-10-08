<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\Ssr\Attribute\AsComponent;

/**
 * platform.calendar — month grid + day agenda + editor over the calendar
 * events feed.
 *
 *     {{ component('platform.calendar', { endpoint: '/platform/calendar/events' }) }}
 *
 * calendar-runtime.js mounts the shell, subscribes the named feed for the
 * visible month on the page's KISS stream (or pulls it once without one) and
 * posts writes to the save/delete routes. It used to be a Twig include with no
 * class, no catalog entry and no contract.
 */
#[AsComponent(
    name: 'platform.calendar',
    template: '@platform-ui/components/runtime/calendar.html.twig',
    cacheable: false,
)]
#[AsUiContract(
    summary: 'A live month calendar with a day agenda and an event editor.',
    props: [
        new UiProp('endpoint', default: '/platform/calendar/events', description: 'The calendar events feed route; save/delete live under it.'),
        new UiProp('feed', default: 'platform-ui.calendar.events', description: 'The feed\'s route name, subscribed through HUG.'),
        new UiProp('view', default: 'month', values: ['month', 'agenda']),
        new UiProp('userId', default: '', description: 'Scope to one user; empty for the shared calendar.'),
    ],
    examples: [
        new UiExample('default', 'Shared calendar', ['endpoint' => '/platform/calendar/events']),
    ],
    // The events feed needs a permission and the host's data.
    previewSafe: false,
)]
final class CalendarComponent
{
}
