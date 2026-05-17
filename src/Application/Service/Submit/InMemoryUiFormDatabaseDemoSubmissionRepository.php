<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit;

use Semitexa\PlatformUi\Domain\Model\Event\UiFormDemoSubmissionCursor;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormDemoSubmissionListCriteria;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormDemoSubmissionPage;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormDemoSubmissionRecord;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormDemoSubmissionSort;

/**
 * Worker-local fallback for
 * {@see UiFormDatabaseDemoSubmissionRepositoryInterface}.
 *
 * Same role the cache-side in-memory variant plays: lazy-default
 * inside {@see UiFormDatabaseDemoSubmissionRepository::getActive()}
 * for unit tests and single-worker dev runs. NOT safe across
 * multiple Swoole workers or PHP-FPM processes — production wires
 * the ORM-backed implementation via SatisfiesRepositoryContract.
 *
 * Storage shape: keeps the same readonly
 * {@see UiFormDemoSubmissionRecord} value object the cache-backed
 * variant stores, so the same dispatch tests can drive both
 * repositories through identical assertions.
 */
/**
 * NOT `final` on purpose — the package's integration-test suite
 * (and downstream callers' tests) override `recent()` / `paginate()`
 * via anonymous `class extends ...` to assert "MUST NOT be called
 * on the deny / bad-cursor path" by making the method throw. That
 * is the legitimate test-double pattern; sealing the class would
 * silently break those tests.
 */
class InMemoryUiFormDatabaseDemoSubmissionRepository implements UiFormDatabaseDemoSubmissionRepositoryInterface
{
    /** @var array<string, UiFormDemoSubmissionRecord> */
    private array $records = [];

    public function save(UiFormDemoSubmissionRecord $record): string
    {
        $this->records[$record->id] = $record;
        return $record->id;
    }

    public function find(string $id): ?UiFormDemoSubmissionRecord
    {
        return $this->records[$id] ?? null;
    }

    public function recent(int $limit = UiFormDatabaseDemoSubmissionRepositoryInterface::DEFAULT_RECENT_LIMIT): array
    {
        return $this->paginate(null, $limit)->records;
    }

    public function paginate(
        ?UiFormDemoSubmissionCursor $cursor = null,
        int $limit = UiFormDatabaseDemoSubmissionRepositoryInterface::DEFAULT_RECENT_LIMIT,
    ): UiFormDemoSubmissionPage {
        return $this->paginateInternal(null, $cursor, $limit);
    }

    public function searchPage(
        UiFormDemoSubmissionListCriteria $criteria,
        ?UiFormDemoSubmissionCursor $cursor = null,
    ): UiFormDemoSubmissionPage {
        return $this->paginateInternal($criteria, $cursor, $criteria->limit);
    }

    /**
     * Shared sort+filter+keyset implementation. `paginate()` passes
     * `criteria = null` (no q / action constraint, the legacy
     * unfiltered DESC path); `searchPage()` passes the validated
     * criteria + its server-resolved sort direction. The repository
     * never reads the raw sort token — only the canonical
     * `(field, direction)` pair from the criteria's sort VO.
     */
    private function paginateInternal(
        ?UiFormDemoSubmissionListCriteria $criteria,
        ?UiFormDemoSubmissionCursor $cursor,
        int $limit,
    ): UiFormDemoSubmissionPage {
        $clamped = max(1, min($limit, UiFormDatabaseDemoSubmissionRepositoryInterface::MAX_RECENT_LIMIT));

        $rows = array_values($this->records);

        if ($criteria !== null) {
            $rows = self::applyCriteria($rows, $criteria);
        }

        // Resolve sort direction. `paginate()` (criteria=null) keeps
        // the legacy DESC default unchanged so v1 callers that never
        // pass a sort token see byte-identical ordering.
        $sortAscending = $criteria !== null
            && $criteria->sort->direction === UiFormDemoSubmissionSort::DIRECTION_ASC;

        if ($sortAscending) {
            usort(
                $rows,
                static function (UiFormDemoSubmissionRecord $a, UiFormDemoSubmissionRecord $b): int {
                    $primary = $a->submittedAt <=> $b->submittedAt;
                    return $primary !== 0 ? $primary : strcmp($a->id, $b->id);
                },
            );
        } else {
            // Newest-first with `id` tie-breaker — matches the ORM
            // ORDER BY `submitted_at` DESC, `id` DESC.
            usort(
                $rows,
                static function (UiFormDemoSubmissionRecord $a, UiFormDemoSubmissionRecord $b): int {
                    $primary = $b->submittedAt <=> $a->submittedAt;
                    return $primary !== 0 ? $primary : strcmp($b->id, $a->id);
                },
            );
        }

        if ($cursor !== null) {
            // Keep only rows strictly past the cursor in the ACTIVE
            // ordering — direction flips the comparison.
            if ($sortAscending) {
                $rows = array_values(array_filter(
                    $rows,
                    static function (UiFormDemoSubmissionRecord $r) use ($cursor): bool {
                        if ($r->submittedAt > $cursor->submittedAt) {
                            return true;
                        }
                        if ($r->submittedAt === $cursor->submittedAt) {
                            return strcmp($r->id, $cursor->id) > 0;
                        }
                        return false;
                    },
                ));
            } else {
                $rows = array_values(array_filter(
                    $rows,
                    static function (UiFormDemoSubmissionRecord $r) use ($cursor): bool {
                        if ($r->submittedAt < $cursor->submittedAt) {
                            return true;
                        }
                        if ($r->submittedAt === $cursor->submittedAt) {
                            return strcmp($r->id, $cursor->id) < 0;
                        }
                        return false;
                    },
                ));
            }
        }

        // Fetch limit + 1 to detect hasMore, then trim.
        $hasMore = count($rows) > $clamped;
        $page    = array_slice($rows, 0, $clamped);
        $nextCursor = ($hasMore && $page !== [])
            ? new UiFormDemoSubmissionCursor(
                submittedAt:       $page[array_key_last($page)]->submittedAt,
                id:                $page[array_key_last($page)]->id,
                filterFingerprint: $criteria?->fingerprint(),
            )
            : null;

        return new UiFormDemoSubmissionPage(
            records:    $page,
            nextCursor: $nextCursor,
            limit:      $clamped,
            hasMore:    $hasMore,
        );
    }

    public function isShared(): bool
    {
        return false;
    }

    public function diagnosticName(): string
    {
        return 'in-memory (worker-local)';
    }

    public function reset(): void
    {
        $this->records = [];
    }

    public function count(): int
    {
        return count($this->records);
    }

    /**
     * Case-insensitive substring match across the three allow-listed
     * contact fields + exact-match action filter. Mirrors the ORM
     * impl's predicate so both repositories agree on which records
     * a given criteria selects.
     *
     * The substring match is run against the in-memory `values_json`
     * shape (a string→scalar map) — the ORM impl runs the same
     * predicate against the serialised `values_json` column via a
     * bound LIKE pattern.
     *
     * @param list<UiFormDemoSubmissionRecord> $rows
     * @return list<UiFormDemoSubmissionRecord>
     */
    private static function applyCriteria(array $rows, UiFormDemoSubmissionListCriteria $criteria): array
    {
        $needle = $criteria->query !== null
            ? mb_strtolower($criteria->query, 'UTF-8')
            : null;
        $action = $criteria->actionName;

        $filtered = [];
        foreach ($rows as $row) {
            if ($action !== null && $row->actionName !== $action) {
                continue;
            }
            if ($needle !== null) {
                $haystack = self::searchableHaystack($row);
                if (!str_contains($haystack, $needle)) {
                    continue;
                }
            }
            $filtered[] = $row;
        }
        return $filtered;
    }

    private static function searchableHaystack(UiFormDemoSubmissionRecord $row): string
    {
        $name    = $row->values['contact_name']    ?? '';
        $topic   = $row->values['contact_topic']   ?? '';
        $message = $row->values['contact_message'] ?? '';
        return mb_strtolower(
            (is_scalar($name)    ? (string) $name    : '') . "\n" .
            (is_scalar($topic)   ? (string) $topic   : '') . "\n" .
            (is_scalar($message) ? (string) $message : ''),
            'UTF-8',
        );
    }
}
