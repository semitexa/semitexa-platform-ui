<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Db\MySQL\Repository;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Core\Tenant\TenantContextAccess;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Orm\Application\Service\OrmBackedStore;
use Semitexa\Orm\Exception\ConstraintViolationException;
use Semitexa\Orm\Exception\StaleAggregateException;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\PlatformUi\Application\Db\MySQL\Model\FormCollabDraftResource;
use Semitexa\PlatformUi\Application\Service\Collaboration\FormCollabDraftRetention;
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
 * Concurrency: two first writes to one scope race to the INSERT; the loser
 * sees the unique key, re-reads, and merges into the winner's row (open() and
 * mergeFields()) or reports a version conflict (apply()).
 *
 * {@see apply()} is read-check-write (optimistic). Two saves that
 * both read version N race to a last-write-wins on the row, but each carries a
 * version stamp, so a client that read an OLDER version is always rejected —
 * the common stale-edit case is caught. True per-scope serialisation of
 * simultaneous writers is the lock store's job (Field/Form-lock modes); the
 * Optimistic baseline intentionally tolerates the last-write-wins window.
 *
 * Every read goes through {@see findByScope()}, which drops a draft the
 * retention rule calls abandoned before anyone sees it; both deletes (that one
 * and {@see discard()}) go through the ORM's #[Version] guard, so an edit that
 * lands in between is never deleted with the old row.
 */
#[SatisfiesRepositoryContract(of: FormCollabDraftStoreInterface::class)]
final class FormCollabDraftDbRepository implements FormCollabDraftStoreInterface
{
    private const MERGE_ATTEMPTS = 5;

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

    #[InjectAsReadonly]
    protected FormCollabDraftRetention $retention;

    /** Test seam — production path uses property injection. */
    public function withRetention(FormCollabDraftRetention $retention): self
    {
        $this->retention = $retention;
        return $this;
    }

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
        // A concurrent open() may seed it first (theirs stands), and a discard
        // may then remove that one: look again rather than fail.
        for ($attempt = 1; $attempt <= self::MERGE_ATTEMPTS; $attempt++) {
            $draft = $this->findByScope($scopeKey) ?? $this->tryInsertDraft($scopeKey, $seedValues, $actor);
            if ($draft !== null) {
                return $draft->toState();
            }
        }

        throw new FormDraftVersionConflictException($scopeKey, 0, $this->currentVersion($scopeKey));
    }

    public function apply(string $scopeKey, array $values, int $expectedVersion, ?string $actor, ?string $expectedEpoch = null): FormCollabDraftState
    {
        $existing = $this->findByScope($scopeKey);
        $currentVersion = $existing?->getVersion() ?? 0;

        // The epoch is the row id, and the update below is addressed to this
        // row's id under its version: the draft that is checked here is the
        // draft that is written, or the write is refused. A draft discarded
        // and replaced since the caller's read has another id.
        if ($currentVersion !== $expectedVersion || ($expectedEpoch !== null && $expectedEpoch !== ($existing?->getId() ?? ''))) {
            throw new FormDraftVersionConflictException($scopeKey, $expectedVersion, $currentVersion);
        }

        if ($existing === null) {
            // expectedVersion === 0 → first write seeds the draft at version 1.
            // Losing that race to another first write is a version conflict:
            // the draft is now at version 1, not the 0 this client read.
            return ($this->tryInsertDraft($scopeKey, $values, $actor)
                ?? throw new FormDraftVersionConflictException($scopeKey, $expectedVersion, $this->currentVersion($scopeKey)))
                ->toState();
        }

        try {
            return $this->updateDraft($existing, $values, $actor)->toState();
        } catch (StaleAggregateException) {
            // Another save landed between our read and our write, or a discard
            // removed the draft: then the current version is 0, and the client
            // is told the document changed, not that the save failed.
            throw new FormDraftVersionConflictException($scopeKey, $expectedVersion, $this->currentVersion($scopeKey));
        }
    }

    /**
     * Field-level merge: two people editing different fields of one form must
     * both keep their edit. The version guard turns a concurrent write into a
     * StaleAggregateException, and the merge is redone on the row as it now
     * stands, a few times, before the edit is reported as a conflict. A row
     * that a discard removed in between is simply absent on the next round:
     * the edit starts the new draft.
     */
    public function mergeFields(string $scopeKey, array $partialValues, ?string $actor): FormCollabDraftState
    {
        for ($attempt = 1; ; $attempt++) {
            $existing = $this->findByScope($scopeKey);
            try {
                if ($existing === null) {
                    // Null: another first edit created the row a moment ago
                    // (1062 on the (tenant_id, scope_key) key, seen live
                    // 2026-10-15). Merge into it on the next round instead of
                    // failing the event and dropping this edit.
                    $inserted = $this->tryInsertDraft($scopeKey, $partialValues, $actor);
                    if ($inserted !== null) {
                        return $inserted->toState();
                    }
                } else {
                    $merged = $existing->getValues();
                    foreach ($partialValues as $field => $value) {
                        $merged[$field] = $value;
                    }

                    return $this->updateDraft($existing, $merged, $actor)->toState();
                }
            } catch (StaleAggregateException) {
                // Written or discarded since the read: redo on what is there now.
            }
            if ($attempt >= self::MERGE_ATTEMPTS) {
                throw new FormDraftVersionConflictException($scopeKey, $existing?->getVersion() ?? 0, $this->currentVersion($scopeKey));
            }
        }
    }

    /**
     * The record is persisted: delete the draft, whatever version it reached.
     * An edit landing between the read and the delete moves the version, the
     * delete is refused, and it is redone on the row as it now stands.
     */
    public function discard(string $scopeKey): void
    {
        for ($attempt = 1; ($existing = $this->readByScope($scopeKey)) !== null; $attempt++) {
            try {
                $this->repository()->delete($existing);

                return;
            } catch (StaleAggregateException $e) {
                if ($attempt >= self::MERGE_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    /**
     * The live draft of a scope: an abandoned one is dropped here, on the way
     * to every reader and writer, and reads as absent.
     */
    private function findByScope(string $scopeKey): ?FormCollabDraft
    {
        $draft = $this->readByScope($scopeKey);
        if ($draft === null || !isset($this->retention)) {
            return $draft;
        }
        if (!$this->retention->isAbandoned($scopeKey, ($draft->getUpdatedAt() ?? new \DateTimeImmutable())->getTimestamp())) {
            return $draft;
        }

        try {
            $this->repository()->delete($draft);
        } catch (StaleAggregateException) {
            // Written to a moment ago: no longer abandoned, and what is there now stands.
        }

        return $this->readByScope($scopeKey);
    }

    private function readByScope(string $scopeKey): ?FormCollabDraft
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
     * Seed the draft at version 1, or return null when a concurrent writer
     * created the same (tenant, scope) row first. That row may already be gone
     * again (a discard): the insert is then tried once more, and a constraint
     * failure with still no row in the way is some other constraint, rethrown.
     *
     * @param array<string, scalar|null> $values
     */
    private function tryInsertDraft(string $scopeKey, array $values, ?string $actor): ?FormCollabDraft
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->insertDraft($scopeKey, $values, 1, $actor);
            } catch (ConstraintViolationException $e) {
                if ($this->readByScope($scopeKey) !== null) {
                    return null;
                }
                if ($attempt >= 2) {
                    throw $e;
                }
            }
        }
    }

    /** The draft's version now, 0 when there is none (never opened, discarded, expired). */
    private function currentVersion(string $scopeKey): int
    {
        return $this->findByScope($scopeKey)?->getVersion() ?? 0;
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
    /**
     * Write the next version over the one read into $existing. The ORM's
     * #[Version] guard bumps it and throws StaleAggregateException when the
     * row moved on in between.
     *
     * @param array<string, scalar|null> $values
     */
    private function updateDraft(FormCollabDraft $existing, array $values, ?string $actor): FormCollabDraft
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
            version:   $existing->getVersion(),
            updatedBy: $actor,
            updatedAt: new \DateTimeImmutable(),
        );

        /** @var FormCollabDraft */
        return $this->repository()->update($draft);
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
