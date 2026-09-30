<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Enum;

/**
 * Permission slugs guarding the calendar endpoints. Granted per user by the
 * application's permission provider (see semitexa/rbac), like any other slug.
 */
enum CalendarPermission: string
{
    /** Read one's own events: the calendar feed. */
    case Read = 'calendar.events.read';

    /** Create, change and delete one's own events. */
    case Write = 'calendar.events.write';
}
