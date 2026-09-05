<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Contract;

use Semitexa\PlatformUi\Domain\Model\CalendarEvent;

/**
 * Reads and writes calendar events for the `platform.calendar` component and
 * any consumer (e.g. the OS planner). Resource is the domain model.
 */
interface CalendarEventRepositoryInterface
{
    /**
     * Events overlapping the window [from, to], ordered by start ascending.
     *
     * @return list<CalendarEvent>
     */
    public function findInRange(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $userId = null): array;

    public function findById(string $id): ?CalendarEvent;

    public function insert(CalendarEvent $event): void;

    public function update(CalendarEvent $event): void;

    public function deleteById(string $id): void;
}
