<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service;

use Semitexa\PlatformUi\Application\Db\MySQL\Model\CalendarEventResource;

/**
 * Serialises a {@see CalendarEventResource} into the wire shape the calendar
 * runtime (and any other consumer, e.g. the OS planner) reads. Single source of
 * truth for the event JSON contract.
 */
final class CalendarEventView
{
    /**
     * @return array{id: string, title: string, starts_at: string, ends_at: string, all_day: bool, location: ?string, notes: ?string, color: ?string}
     */
    public static function toArray(CalendarEventResource $event): array
    {
        return [
            'id' => $event->id,
            'title' => $event->title,
            'starts_at' => $event->starts_at->format('c'),
            'ends_at' => $event->ends_at->format('c'),
            'all_day' => $event->all_day === 1,
            'location' => $event->location,
            'notes' => $event->notes,
            'color' => $event->color,
        ];
    }
}
