<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Db\MySQL\Repository;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Direction;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\PlatformUi\Application\Db\MySQL\Model\CalendarEventResource;
use Semitexa\PlatformUi\Domain\Contract\CalendarEventRepositoryInterface;

/**
 * Database-backed calendar event repository. Writes go through
 * {@see DomainRepository}, so the ORM's {@see \Semitexa\Orm\Application\Service\Persistence\AggregateWriteEngine}
 * auto-publishes a `ResourceChangedEvent` (scope = `platform_calendar_events`),
 * which the held-open SSE feed watches to re-run live.
 */
#[SatisfiesRepositoryContract(of: CalendarEventRepositoryInterface::class)]
final class CalendarEventDbRepository implements CalendarEventRepositoryInterface
{
    #[InjectAsReadonly]
    protected OrmManager $orm;

    private ?DomainRepository $repository = null;

    public function findInRange(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $userId = null): array
    {
        // An event overlaps the window when it starts on/before the window end
        // AND ends on/after the window start.
        $query = $this->repository()->query()
            ->where(CalendarEventResource::column('starts_at'), Operator::LessThanOrEquals, $to->format('Y-m-d H:i:s'))
            ->where(CalendarEventResource::column('ends_at'), Operator::GreaterThanOrEquals, $from->format('Y-m-d H:i:s'))
            ->orderBy(CalendarEventResource::column('starts_at'), Direction::Asc);
        $this->applyUserScope($query, $userId);

        /** @var list<CalendarEventResource> $rows */
        $rows = $query->fetchAllAs(CalendarEventResource::class, $this->orm()->getMapperRegistry());

        return $rows;
    }

    public function findById(string $id): ?CalendarEventResource
    {
        /** @var CalendarEventResource|null $resource */
        $resource = $this->repository()->query()
            ->where(CalendarEventResource::column('id'), Operator::Equals, $id)
            ->fetchOneAs(CalendarEventResource::class, $this->orm()->getMapperRegistry());

        return $resource;
    }

    public function insert(CalendarEventResource $event): void
    {
        $this->repository()->insert($event);
    }

    public function update(CalendarEventResource $event): void
    {
        $this->repository()->update($event);
    }

    public function deleteById(string $id): void
    {
        $existing = $this->findById($id);
        if ($existing !== null) {
            $this->repository()->delete($existing);
        }
    }

    private function applyUserScope(object $query, ?string $userId): void
    {
        if ($userId === null) {
            $query->whereNull(CalendarEventResource::column('user_id'));

            return;
        }

        $query->where(CalendarEventResource::column('user_id'), Operator::Equals, $userId);
    }

    private function repository(): DomainRepository
    {
        return $this->repository ??= $this->orm()->repository(CalendarEventResource::class, CalendarEventResource::class);
    }

    private function orm(): OrmManager
    {
        return $this->orm ??= new OrmManager();
    }
}
