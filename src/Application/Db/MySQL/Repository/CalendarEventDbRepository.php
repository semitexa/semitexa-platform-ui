<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Db\MySQL\Repository;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Core\Tenant\TenantContextAccess;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
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
 *
 * Tenant posture (fail-closed): the resource is `#[TenantScoped]`, so every
 * read runs on the ambient-tenant view — `forTenant(currentTenantId())`, with
 * the literal `'default'` sentinel for the default/single-tenant context (the
 * {@see FormCollabDraftDbRepository} convention). Writes stamp `tenant_id`
 * when the row does not carry one yet, so pre-tenancy callers keep working
 * and every persisted row is owned. One tenant can never read, update or
 * delete another tenant's events through this repository.
 */
#[SatisfiesRepositoryContract(of: CalendarEventRepositoryInterface::class)]
final class CalendarEventDbRepository implements CalendarEventRepositoryInterface
{
    #[InjectAsReadonly]
    protected OrmManager $orm;

    /**
     * The ambient-tenant seam: a singleton service whose get()/tryGet() read
     * the coroutine-local request context, so resolving the tenant AT CALL
     * TIME stays per-request-correct without making this repository (and
     * every consuming handler) execution-scoped.
     */
    #[InjectAsReadonly]
    protected TenantContextStoreInterface $tenantContextStore;

    private ?DomainRepository $repository = null;

    /** Test seam — production path uses property injection. */
    public function withOrmManager(OrmManager $orm): self
    {
        $this->orm = $orm;
        $this->repository = null;
        return $this;
    }

    /** Test seam — production path uses property injection. */
    public function withTenantContextStore(TenantContextStoreInterface $store): self
    {
        $this->tenantContextStore = $store;
        return $this;
    }

    public function findInRange(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $userId = null): array
    {
        // An event overlaps the window when it starts on/before the window end
        // AND ends on/after the window start.
        $query = $this->scoped()->query()
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
        $resource = $this->scoped()->query()
            ->where(CalendarEventResource::column('id'), Operator::Equals, $id)
            ->fetchOneAs(CalendarEventResource::class, $this->orm()->getMapperRegistry());

        return $resource;
    }

    public function insert(CalendarEventResource $event): void
    {
        $this->scoped()->insert($this->stamped($event));
    }

    public function update(CalendarEventResource $event): void
    {
        $this->scoped()->update($this->stamped($event));
    }

    public function deleteById(string $id): void
    {
        // findById is tenant-scoped, so a foreign tenant's id resolves to
        // null and the delete is a no-op — cross-tenant deletes are impossible.
        $existing = $this->findById($id);
        if ($existing !== null) {
            $this->scoped()->delete($existing);
        }
    }

    /** Every persisted row is owned: stamp the ambient tenant when absent. */
    private function stamped(CalendarEventResource $event): CalendarEventResource
    {
        return $event->tenant_id === null
            ? $event->copyWith(['tenant_id' => $this->currentTenantId(), 'updated_at' => $event->updated_at])
            : $event;
    }

    /**
     * The current tenant id, or the 'default' sentinel for the
     * default/single-tenant context — never null, so the fail-closed
     * tenant filter always binds a concrete value.
     */
    private function currentTenantId(): string
    {
        $context = isset($this->tenantContextStore) ? $this->tenantContextStore->tryGet() : null;

        return TenantContextAccess::tenantIdOrDefault($context);
    }

    private function scoped(): DomainRepository
    {
        return $this->repository()->forTenant($this->currentTenantId());
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
