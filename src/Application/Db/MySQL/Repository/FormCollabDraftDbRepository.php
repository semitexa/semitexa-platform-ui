<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Db\MySQL\Repository;

use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Core\Tenant\TenantContextAccess;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\PlatformUi\Application\Db\MySQL\Model\FormCollabDraftResource;
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
    #[InjectAsReadonly]
    protected OrmManager $orm;

    /**
     * The ambient tenant, so a draft row is scoped to its owner: two tenants
     * sharing a `formKey`/`recordId` get separate rows and never read or
     * overwrite each other's in-progress edits. Null in single-tenant / default
     * contexts (e.g. the playground), which keeps that behaviour unchanged.
     */
    #[InjectAsMutable]
    protected ?TenantContextInterface $tenantContext = null;

    private ?DomainRepository $repository = null;

    /** Test seam — production path uses property injection. */
    public function withOrmManager(OrmManager $orm): self
    {
        $this->orm = $orm;
        $this->repository = null;
        return $this;
    }

    /** Test seam — production path uses property injection. */
    public function withTenantContext(?TenantContextInterface $tenantContext): self
    {
        $this->tenantContext = $tenantContext;
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
        return TenantContextAccess::tenantIdOrDefault($this->tenantContext);
    }

    public function load(string $scopeKey): ?FormCollabDraftState
    {
        $resource = $this->findByScope($scopeKey);

        return $resource === null ? null : self::toState($resource);
    }

    public function open(string $scopeKey, array $seedValues, ?string $actor): FormCollabDraftState
    {
        $existing = $this->findByScope($scopeKey);
        if ($existing !== null) {
            return self::toState($existing);
        }

        return self::toState($this->insertDraft($scopeKey, $seedValues, 1, $actor));
    }

    public function apply(string $scopeKey, array $values, int $expectedVersion, ?string $actor): FormCollabDraftState
    {
        $existing = $this->findByScope($scopeKey);
        $currentVersion = $existing?->version ?? 0;

        if ($currentVersion !== $expectedVersion) {
            throw new FormDraftVersionConflictException($scopeKey, $expectedVersion, $currentVersion);
        }

        if ($existing === null) {
            // expectedVersion === 0 → first write seeds the draft at version 1.
            return self::toState($this->insertDraft($scopeKey, $values, 1, $actor));
        }

        return self::toState($this->updateDraft($existing, $values, $currentVersion + 1, $actor));
    }

    public function mergeFields(string $scopeKey, array $partialValues, ?string $actor): FormCollabDraftState
    {
        $existing = $this->findByScope($scopeKey);
        if ($existing === null) {
            return self::toState($this->insertDraft($scopeKey, $partialValues, 1, $actor));
        }

        $merged = self::decodeValues($existing);
        foreach ($partialValues as $field => $value) {
            $merged[$field] = $value;
        }

        return self::toState($this->updateDraft($existing, $merged, $existing->version + 1, $actor));
    }

    private function findByScope(string $scopeKey): ?FormCollabDraftResource
    {
        // Scope to the owning tenant so the same scope_key under another tenant
        // is never read. Default/single-tenant rows carry the 'default' sentinel.
        /** @var FormCollabDraftResource|null $resource */
        $resource = $this->repository()->query()
            ->where(FormCollabDraftResource::column('scope_key'), Operator::Equals, $scopeKey)
            ->where(FormCollabDraftResource::column('tenant_id'), Operator::Equals, $this->currentTenantId())
            ->fetchOneAs(FormCollabDraftResource::class, $this->orm()->getMapperRegistry());

        return $resource;
    }

    /**
     * @param array<string, scalar|null> $values
     */
    private function insertDraft(string $scopeKey, array $values, int $version, ?string $actor): FormCollabDraftResource
    {
        $resource = new FormCollabDraftResource(
            id:          self::mintId(),
            tenant_id:   $this->currentTenantId(),
            scope_key:   $scopeKey,
            values_json: self::encodeValues($values),
            version:     $version,
            updated_by:  $actor,
            updated_at:  new \DateTimeImmutable(),
        );
        $this->repository()->insert($resource);

        return $resource;
    }

    /**
     * @param array<string, scalar|null> $values
     */
    private function updateDraft(FormCollabDraftResource $existing, array $values, int $version, ?string $actor): FormCollabDraftResource
    {
        $resource = new FormCollabDraftResource(
            id:          $existing->id,
            tenant_id:   $existing->tenant_id,
            scope_key:   $existing->scope_key,
            values_json: self::encodeValues($values),
            version:     $version,
            updated_by:  $actor,
            updated_at:  new \DateTimeImmutable(),
        );
        $this->repository()->update($resource);

        return $resource;
    }

    private static function mintId(): string
    {
        return 'fcd_' . bin2hex(random_bytes(8));
    }

    /**
     * @param array<string, scalar|null> $values
     */
    private static function encodeValues(array $values): string
    {
        return json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, scalar|null>
     */
    private static function decodeValues(FormCollabDraftResource $resource): array
    {
        $values = [];
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($resource->values_json, true, 16, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    if (is_string($key) && (is_scalar($value) || $value === null)) {
                        $values[$key] = $value;
                    }
                }
            }
        } catch (\JsonException) {
            // Corrupted row → empty values; the draft surface stays safe.
            $values = [];
        }

        return $values;
    }

    private static function toState(FormCollabDraftResource $resource): FormCollabDraftState
    {
        return new FormCollabDraftState(
            scopeKey:  $resource->scope_key,
            values:    self::decodeValues($resource),
            version:   $resource->version,
            updatedBy: $resource->updated_by,
            updatedAt: $resource->updated_at->getTimestamp(),
        );
    }

    private function repository(): DomainRepository
    {
        return $this->repository ??= $this->orm()->repository(
            FormCollabDraftResource::class,
            FormCollabDraftResource::class,
        );
    }

    private function orm(): OrmManager
    {
        return $this->orm ??= new OrmManager();
    }
}
