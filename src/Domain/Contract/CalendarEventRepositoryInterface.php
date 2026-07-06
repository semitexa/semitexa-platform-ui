<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Contract;

use Semitexa\PlatformUi\Application\Db\MySQL\Model\CalendarEventResource;

/**
 * Reads and writes calendar events for the `platform.calendar` component and
 * any consumer (e.g. the OS planner). Resource is the domain model.
 */
interface CalendarEventRepositoryInterface
{
    /**
     * Events overlapping the window [from, to], ordered by start ascending.
     *
     * @return list<CalendarEventResource>
     */
    public function findInRange(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $userId = null): array;

    public function findById(string $id): ?CalendarEventResource;

    public function insert(CalendarEventResource $event): void;

    public function update(CalendarEventResource $event): void;

    public function deleteById(string $id): void;
}
