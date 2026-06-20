<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Collaboration;

use Semitexa\PlatformUi\Domain\Contract\FormCollabDraftStoreInterface;
use Semitexa\PlatformUi\Domain\Exception\FormDraftVersionConflictException;
use Semitexa\PlatformUi\Domain\Model\Collaboration\FormCollabDraftState;

/**
 * Worker-local fallback for {@see FormCollabDraftStoreInterface} — the same
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
class InMemoryFormCollabDraftStore implements FormCollabDraftStoreInterface
{
    /** @var array<string, FormCollabDraftState> */
    private array $drafts = [];

    public function load(string $scopeKey): ?FormCollabDraftState
    {
        return $this->drafts[$scopeKey] ?? null;
    }

    public function open(string $scopeKey, array $seedValues, ?string $actor): FormCollabDraftState
    {
        return $this->drafts[$scopeKey] ??= new FormCollabDraftState(
            scopeKey:  $scopeKey,
            values:    self::sanitize($seedValues),
            version:   1,
            updatedBy: $actor,
            updatedAt: time(),
        );
    }

    public function apply(string $scopeKey, array $values, int $expectedVersion, ?string $actor): FormCollabDraftState
    {
        $current = $this->drafts[$scopeKey] ?? null;
        $currentVersion = $current?->version ?? 0;

        if ($currentVersion !== $expectedVersion) {
            throw new FormDraftVersionConflictException($scopeKey, $expectedVersion, $currentVersion);
        }

        return $this->store($scopeKey, self::sanitize($values), $currentVersion + 1, $actor);
    }

    public function mergeFields(string $scopeKey, array $partialValues, ?string $actor): FormCollabDraftState
    {
        $current = $this->drafts[$scopeKey] ?? null;
        $merged = $current?->values ?? [];
        foreach (self::sanitize($partialValues) as $field => $value) {
            $merged[$field] = $value;
        }

        return $this->store($scopeKey, $merged, ($current?->version ?? 0) + 1, $actor);
    }

    /**
     * @param array<string, scalar|null> $values
     */
    private function store(string $scopeKey, array $values, int $version, ?string $actor): FormCollabDraftState
    {
        return $this->drafts[$scopeKey] = new FormCollabDraftState(
            scopeKey:  $scopeKey,
            values:    $values,
            version:   $version,
            updatedBy: $actor,
            updatedAt: time(),
        );
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
