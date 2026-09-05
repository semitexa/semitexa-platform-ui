<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service;

use Semitexa\PlatformUi\Domain\Model\CalendarEvent;

/**
 * Serialises a {@see CalendarEvent} into the wire shape the calendar
 * runtime (and any other consumer, e.g. the OS planner) reads. Single source of
 * truth for the event JSON contract.
 */
final class CalendarEventView
{
    /**
     * @return array{id: string, title: string, starts_at: string, ends_at: string, all_day: bool, location: ?string, notes: ?string, color: ?string}
     */
    public static function toArray(CalendarEvent $event): array
    {
        return [
            'id' => $event->getId(),
            'title' => $event->getTitle(),
            'starts_at' => $event->getStartsAt()->format('c'),
            'ends_at' => $event->getEndsAt()->format('c'),
            'all_day' => $event->isAllDay(),
            'location' => $event->getLocation(),
            'notes' => $event->getNotes(),
            'color' => $event->getColor(),
        ];
    }
}
