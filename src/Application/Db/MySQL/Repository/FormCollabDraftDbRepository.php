<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Db\MySQL\Repository;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Core\Tenant\TenantContextAccess;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Orm\Application\Service\OrmBackedStore;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\PlatformUi\Application\Db\MySQL\Model\FormCollabDraftResource;
use Semitexa\PlatformUi\Domain\Model\Collaboration\FormCollabDraft;
use Semitexa\PlatformUi\Domain\Contract\FormCollabDraftStoreInterface;
use Semitexa\PlatformUi\Domain\Exception\FormDraftVersionConflictException;
use Semitexa\PlatformUi\Domain\Model\Collaboration\FormCollabDraftState;

/**
 * Database-backed collaborative-form draft store — the production default for
 * {@see FormCollabDraftStoreInterface}.
 *
 * Mirrors the {@see UiFormDemoSubmissionDbRepository} pattern verbatim:
 * `#[SatisfiesRepositoryContract]`, `OrmManager` via `#[InjectAsReadonly]`,
 * lazy `DomainRepository` memoisation, a `withOrmManager()` test seam, and
 * `values_json` as the same safe `json_encode()` shape.
 *
 * Concurrency: {@see apply()} is read-check-write (optimistic). Two saves that
 * both read version N race to a last-write-wins on the row, but each carries a
 * version stamp, so a client that read an OLDER version is always rejected —
 * the common stale-edit case is caught. True per-scope serialisation of
 * simultaneous writers is the lock store's job (Field/Form-lock modes); the
 * Optimistic baseline intentionally tolerates the last-write-wins window.
 */
#[SatisfiesRepositoryContract(of: FormCollabDraftStoreInterface::class)]
final class FormCollabDraftDbRepository implements FormCollabDraftStoreInterface
{
    use OrmBackedStore;

    #[InjectAsReadonly]
    protected OrmManager $orm;

    /**
     * The ambient-tenant seam (the {@see CalendarEventDbRepository} pattern):
     * a singleton service whose tryGet() reads the coroutine-local request
     * context, so the tenant is resolved AT CALL TIME and stays per-request
     * correct WITHOUT making this repository execution-scoped. The previous
     * `#[ExecutionScoped]` + mutable-context shape made this class
     * un-injectable as a readonly contract into eagerly-built services, which
     * is exactly why dev/showcase consumers had to bind the WORKER-LOCAL
     * in-memory store instead — silently breaking cross-worker live sync
     * (the collab-lock E2E red: a re-run on another worker served draft
     * values from that worker's stale memory).
     *
     * Tenant semantics are unchanged: two tenants sharing a
     * `formKey`/`recordId` get separate rows and never read or overwrite
     * each other's in-progress edits.
     */
    #[InjectAsReadonly]
    protected TenantContextStoreInterface $tenantContextStore;

    /** Test seam — production path uses property injection. */
    public function withTenantContext(?TenantContextInterface $tenantContext): self
    {
        $this->tenantContextStore = new class ($tenantContext) implements TenantContextStoreInterface {
            public function __construct(private ?TenantContextInterface $context)
            {
            }

            public function get(): TenantContextInterface
            {
                return $this->context ?? throw new \LogicException('No tenant context in the fixed test store.');
            }

            public function tryGet(): ?TenantContextInterface
            {
                return $this->context;
            }

            public function set(TenantContextInterface $context): void
            {
                $this->context = $context;
            }

            public function clear(): void
            {
                $this->context = null;
            }
        };
        return $this;
    }

    /**
     * The current tenant id, or the 'default' sentinel for the
     * default/single-tenant context. Never null: the unique index spans
     * (tenant_id, scope_key) and MySQL treats NULLs as distinct, so a NULL
     * tenant would silently drop the per-scope uniqueness guarantee.
     */
    private function currentTenantId(): string
    {
        $context = isset($this->tenantContextStore) ? $this->tenantContextStore->tryGet() : null;

        return TenantContextAccess::tenantIdOrDefault($context);
    }

    public function load(string $scopeKey): ?FormCollabDraftState
    {
        $resource = $this->findByScope($scopeKey);

        return $resource?->toState();
    }

    public function open(string $scopeKey, array $seedValues, ?string $actor): FormCollabDraftState
    {
        $existing = $this->findByScope($scopeKey);
        if ($existing !== null) {
            return $existing->toState();
        }

        return $this->insertDraft($scopeKey, $seedValues, 1, $actor)->toState();
    }

    public function apply(string $scopeKey, array $values, int $expectedVersion, ?string $actor): FormCollabDraftState
    {
        $existing = $this->findByScope($scopeKey);
        $currentVersion = $existing?->getVersion() ?? 0;

        if ($currentVersion !== $expectedVersion) {
            throw new FormDraftVersionConflictException($scopeKey, $expectedVersion, $currentVersion);
        }

        if ($existing === null) {
            // expectedVersion === 0 → first write seeds the draft at version 1.
            return $this->insertDraft($scopeKey, $values, 1, $actor)->toState();
        }

        return $this->updateDraft($existing, $values, $currentVersion + 1, $actor)->toState();
    }

    public function mergeFields(string $scopeKey, array $partialValues, ?string $actor): FormCollabDraftState
    {
        $existing = $this->findByScope($scopeKey);
        if ($existing === null) {
            return $this->insertDraft($scopeKey, $partialValues, 1, $actor)->toState();
        }

        $merged = $existing->getValues();
        foreach ($partialValues as $field => $value) {
            $merged[$field] = $value;
        }

        return $this->updateDraft($existing, $merged, $existing->getVersion() + 1, $actor)->toState();
    }

    private function findByScope(string $scopeKey): ?FormCollabDraft
    {
        // Scope to the owning tenant so the same scope_key under another tenant
        // is never read. Default/single-tenant rows carry the 'default' sentinel.
        /** @var FormCollabDraft|null $resource */
        $resource = $this->repository()->query()
            ->where(FormCollabDraftResource::column('scope_key'), Operator::Equals, $scopeKey)
            ->where(FormCollabDraftResource::column('tenant_id'), Operator::Equals, $this->currentTenantId())
            ->fetchOneAs(FormCollabDraft::class, $this->mapperRegistry());

        return $resource;
    }

    /**
     * @param array<string, scalar|null> $values
     */
    private function insertDraft(string $scopeKey, array $values, int $version, ?string $actor): FormCollabDraft
    {
        $draft = new FormCollabDraft(
            id:        self::mintId(),
            tenantId:  $this->currentTenantId(),
            scopeKey:  $scopeKey,
            values:    $values,
            version:   $version,
            updatedBy: $actor,
            updatedAt: new \DateTimeImmutable(),
        );
        $this->repository()->insert($draft);

        return $draft;
    }

    /**
     * @param array<string, scalar|null> $values
     */
    private function updateDraft(FormCollabDraft $existing, array $values, int $version, ?string $actor): FormCollabDraft
    {
        $draft = new FormCollabDraft(
            id:        $existing->getId(),
            // Normalise to the current tenant so a legacy row written before
            // the tenant_id column existed (NULL) heals to the 'default'
            // sentinel instead of staying NULL forever and slipping past the
            // (tenant_id, scope_key) uniqueness guarantee.
            tenantId:  $this->currentTenantId(),
            scopeKey:  $existing->getScopeKey(),
            values:    $values,
            version:   $version,
            updatedBy: $actor,
            updatedAt: new \DateTimeImmutable(),
        );
        $this->repository()->update($draft);

        return $draft;
    }

    private static function mintId(): string
    {
        return 'fcd_' . bin2hex(random_bytes(8));
    }

    private function repository(): DomainRepository
    {
        return $this->domainRepository(FormCollabDraftResource::class, FormCollabDraft::class);
    }

}
