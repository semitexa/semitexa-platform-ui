<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit;

use Semitexa\PlatformUi\Domain\Model\Event\UiFormDemoSubmissionRecord;

/**
 * Worker-local in-memory demo submission repository.
 *
 * Used as the lazy-default fallback inside
 * {@see UiFormDemoSubmissionRepository::getActive()} for unit tests
 * and single-worker dev runs. NOT safe across multiple Swoole workers
 * or PHP-FPM processes: a record saved on one worker is invisible to
 * a `find()` call landing on another. Production deployments use
 * {@see CacheBackedUiFormDemoSubmissionRepository} (auto-bound via
 * `#[SatisfiesServiceContract]`).
 *
 * Storage shape: keeps the same {@see UiFormDemoSubmissionRecord} the
 * cache-backed impl stores, plus a `time()`-based expiresAt so the
 * 24h-equivalent TTL is observable in tests without sleeping.
 */
final class InMemoryUiFormDemoSubmissionRepository implements UiFormDemoSubmissionRepositoryInterface
{
    /** @var array<string, array{record: UiFormDemoSubmissionRecord, expiresAt: int}> */
    private array $records = [];

    public function save(UiFormDemoSubmissionRecord $record): string
    {
        $this->records[$record->id] = [
            'record' => $record,
            'expiresAt' => time() + CacheBackedUiFormDemoSubmissionRepository::TTL_SECONDS,
        ];
        return $record->id;
    }

    public function find(string $id): ?UiFormDemoSubmissionRecord
    {
        $this->purgeExpired();
        return $this->records[$id]['record'] ?? null;
    }

    public function isShared(): bool
    {
        return false;
    }

    public function diagnosticName(): string
    {
        return 'in-memory (worker-local)';
    }

    /** Test/reset hook. */
    public function reset(): void
    {
        $this->records = [];
    }

    /**
     * Total count of live records — test-only helper.
     */
    public function count(): int
    {
        $this->purgeExpired();
        return count($this->records);
    }

    private function purgeExpired(): void
    {
        $now = time();
        foreach ($this->records as $id => $entry) {
            if ($entry['expiresAt'] <= $now) {
                unset($this->records[$id]);
            }
        }
    }
}
