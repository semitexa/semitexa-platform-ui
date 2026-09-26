<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Security;

/**
 * Permission slugs guarding the calendar endpoints. Granted per user by the
 * application's permission provider (see semitexa/rbac), like any other slug.
 */
final class CalendarPermission
{
    /** Read one's own events: the calendar feed. */
    public const READ = 'calendar.events.read';

    /** Create, change and delete one's own events. */
    public const WRITE = 'calendar.events.write';

    private function __construct()
    {
    }
}
