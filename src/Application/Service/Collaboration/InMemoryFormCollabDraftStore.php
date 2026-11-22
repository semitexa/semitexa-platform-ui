<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Collaboration;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Tenant\TenantContextAccess;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\PlatformUi\Domain\Contract\FormCollabOrderedDraftStoreInterface;
use Semitexa\PlatformUi\Domain\Exception\FormDraftVersionConflictException;
use Semitexa\PlatformUi\Domain\Model\Collaboration\FormCollabDraftState;
use Semitexa\PlatformUi\Domain\Model\Collaboration\FormFieldEditOrder;

/**
 * Worker-local fallback for {@see FormCollabOrderedDraftStoreInterface} — the same
 * role {@see \Semitexa\PlatformUi\Application\Service\Submit\InMemoryUiFormDatabaseDemoSubmissionRepository}
 * plays for demo submissions: a default for unit tests and single-worker dev
 * runs. NOT safe across multiple Swoole workers (the map is per-process);
 * production wires the ORM-backed {@see \Semitexa\PlatformUi\Application\Db\MySQL\Repository\FormCollabDraftDbRepository}
 * via `#[SatisfiesRepositoryContract]`.
 *
 * It implements the SAME draft semantics (idempotent open, optimistic
 * version-guarded apply, last-write-wins mergeFields) so the contract test
 * pins both stores' behaviour through one set of assertions.
 *
 * NOT `final` so tests can extend it to assert call discipline, matching the
 * demo in-memory repository convention.
 */
class InMemoryFormCollabDraftStore implements FormCollabOrderedDraftStoreInterface
{
    /** @var array<string, array<string, FormCollabDraftState>> tenant key → scope key → draft */
    private array $drafts = [];

    /**
     * The edit marks of each draft, with the epoch of the draft they belong
     * to: a draft that replaced a discarded or expired one starts with none.
     *
     * @var array<string, array<string, array{epoch: string, marks: array<string, array<string, int>>}>>
     */
    private array $editMarks = [];

    /**
     * Optional tenant partition, mirroring the DB store so both implementations
     * isolate drafts the same way. This store is a worker-local singleton (its
     * draft map must survive across requests), so it canNOT be #[ExecutionScoped]
     * for per-request mutable injection; the tenant is supplied via
     * {@see withTenantContext()} (used by tests). Left null in the
     * single-worker dev / single-tenant default it actually serves.
     */
    protected ?TenantContextInterface $tenantContext = null;

    /**
     * When a draft is abandoned ({@see FormCollabDraftRetention}): injected
     * when an app binds this store through the container, as it is into the
     * ORM store, so the two expire alike. Constructed by hand (a unit test)
     * it is unset and a draft ends only by discard().
     */
    #[InjectAsReadonly]
    protected FormCollabDraftRetention $retention;

    /** Test seam — production path uses property injection. */
    public function withTenantContext(?TenantContextInterface $tenantContext): self
    {
        $this->tenantContext = $tenantContext;
        return $this;
    }

    /** Test seam — production path uses property injection. */
    public function withRetention(FormCollabDraftRetention $retention): self
    {
        $this->retention = $retention;
        return $this;
    }

    public function load(string $scopeKey): ?FormCollabDraftState
    {
        return $this->live($scopeKey);
    }

    public function discard(string $scopeKey): void
    {
        unset($this->drafts[$this->tenantKey()][$scopeKey], $this->editMarks[$this->tenantKey()][$scopeKey]);
    }

    /**
     * The draft, unless it was abandoned: then it is dropped and absent.
     *
     * Asking whether it is abandoned can suspend (a presence read), and the
     * answer is about the draft read BEFORE it. One written, replaced or
     * discarded meanwhile is what stands: it is returned as it is now, and
     * never dropped on the older draft's account.
     */
    private function live(string $scopeKey): ?FormCollabDraftState
    {
        $tenant = $this->tenantKey();
        $draft = $this->drafts[$tenant][$scopeKey] ?? null;
        if ($draft === null || !isset($this->retention)) {
            return $draft;
        }

        $abandoned = $this->retention->isAbandoned($scopeKey, $draft->updatedAt);
        $now = $this->drafts[$tenant][$scopeKey] ?? null;
        if ($now !== $draft) {
            return $now;
        }
        if ($abandoned) {
            unset($this->drafts[$tenant][$scopeKey], $this->editMarks[$tenant][$scopeKey]);

            return null;
        }

        return $draft;
    }

    public function open(string $scopeKey, array $seedValues, ?string $actor): FormCollabDraftState
    {
        return $this->live($scopeKey) ?? $this->drafts[$this->tenantKey()][$scopeKey] = new FormCollabDraftState(
            scopeKey:  $scopeKey,
            values:    self::sanitize($seedValues),
            version:   1,
            updatedBy: $actor,
            updatedAt: time(),
            epoch:     self::mintEpoch(),
        );
    }

    public function apply(string $scopeKey, array $values, int $expectedVersion, ?string $actor, ?string $expectedEpoch = null): FormCollabDraftState
    {
        $current = $this->live($scopeKey);
        $currentVersion = $current?->version ?? 0;

        if ($currentVersion !== $expectedVersion || ($expectedEpoch !== null && $expectedEpoch !== ($current->epoch ?? ''))) {
            throw new FormDraftVersionConflictException($scopeKey, $expectedVersion, $currentVersion);
        }

        return $this->store($scopeKey, self::sanitize($values), $currentVersion + 1, $actor, $current?->epoch);
    }

    public function mergeFields(string $scopeKey, array $partialValues, ?string $actor): FormCollabDraftState
    {
        $current = $this->live($scopeKey);
        $merged = $current?->values ?? [];
        foreach (self::sanitize($partialValues) as $field => $value) {
            $merged[$field] = $value;
        }

        return $this->store($scopeKey, $merged, ($current->version ?? 0) + 1, $actor, $current?->epoch);
    }

    public function mergeFieldInOrder(
        string $scopeKey,
        string $field,
        int|float|string|bool|null $value,
        ?string $actor,
        string $source,
        int $sequence,
    ): FormFieldEditOrder {
        // The one step that can suspend: whether the draft is abandoned may
        // be a presence read. Another edit can be stored, or the draft
        // replaced, meanwhile, so what it returns is not used. Everything
        // after it reads the draft and its marks as they stand NOW and
        // writes both without yielding: the check and the write are one step.
        $this->live($scopeKey);

        $tenant = $this->tenantKey();
        $current = $this->drafts[$tenant][$scopeKey] ?? null;
        $kept = $this->editMarks[$tenant][$scopeKey] ?? null;
        // Marks of another draft (this one replaced it) are not this draft's.
        $marks = $current !== null && $kept !== null && $kept['epoch'] === $current->epoch ? $kept['marks'] : [];

        [$order, $marks] = FormFieldEditMarks::place($marks, $field, $source, $sequence);
        if ($order === FormFieldEditOrder::Applied) {
            $merged = $current->values ?? [];
            $merged[$field] = $value;
            $stored = $this->store($scopeKey, $merged, ($current->version ?? 0) + 1, $actor, $current?->epoch);
            $this->editMarks[$tenant][$scopeKey] = ['epoch' => $stored->epoch, 'marks' => $marks];
        }

        return $order;
    }

    /**
     * @param array<string, scalar|null> $values
     */
    private function store(string $scopeKey, array $values, int $version, ?string $actor, ?string $epoch): FormCollabDraftState
    {
        return $this->drafts[$this->tenantKey()][$scopeKey] = new FormCollabDraftState(
            scopeKey:  $scopeKey,
            values:    $values,
            version:   $version,
            updatedBy: $actor,
            updatedAt: time(),
            epoch:     $epoch ?? self::mintEpoch(),
        );
    }

    /** One per draft, as the ORM store's row id is. */
    private static function mintEpoch(): string
    {
        return 'fcd_' . bin2hex(random_bytes(8));
    }

    /** The map partition for the current tenant ('default' for the default context). */
    private function tenantKey(): string
    {
        return TenantContextAccess::tenantIdOrDefault($this->tenantContext);
    }

    /**
     * @param array<string, scalar|null> $values
     * @return array<string, scalar|null>
     */
    private static function sanitize(array $values): array
    {
        $clean = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && (is_scalar($value) || $value === null)) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
