<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit;

use Semitexa\PlatformUi\Domain\Model\Event\UiFormDemoSubmissionCursor;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormDemoSubmissionListCriteria;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormDemoSubmissionPage;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormDemoSubmissionRecord;

/**
 * Database-backed counterpart of
 * {@see UiFormDemoSubmissionRepositoryInterface}.
 *
 * Same shape on purpose — the cache-backed and DB-backed demo
 * repositories are interchangeable from the caller's perspective.
 * The action chooses which one it needs by typing against this
 * interface (vs. the cache one), keeping the two storage
 * mechanisms cleanly separated and avoiding a silent swap of the
 * cache-backed `platform.demo.storeContact` action's behaviour.
 *
 * Demo storage, not a real data layer:
 *   - production default impl is ORM-backed
 *     ({@see Db\MySQL\Repository\UiFormDemoSubmissionDbRepository});
 *   - test / single-worker fallback is
 *     {@see InMemoryUiFormDatabaseDemoSubmissionRepository};
 *   - the static {@see UiFormDatabaseDemoSubmissionRepository}
 *     holder follows the same transitional-bridge pattern used
 *     across the package.
 *
 * Trust perimeter:
 *   - the repository persists ONLY the {@see UiFormDemoSubmissionRecord}
 *     shape it is given. Tokens / signed-ctx blobs / dispatchIds /
 *     debug internals never appear in the table.
 *   - sanitisation happens in the action — the repository is a
 *     dumb sink.
 */
interface UiFormDatabaseDemoSubmissionRepositoryInterface
{
    /**
     * Maximum number of rows the read-only listing methods will ever
     * return. The diagnostic listing in the UiPlayground module
     * deliberately keeps this small — there is no pagination, no
     * search, no export in this slice.
     */
    public const MAX_RECENT_LIMIT = 100;

    /**
     * Default page size for {@see recent()} when the caller does not
     * supply one.
     */
    public const DEFAULT_RECENT_LIMIT = 25;

    /**
     * Persist the record. Returns the same id the record carries
     * (the caller — the action — has already generated `uifs_<…>`).
     */
    public function save(UiFormDemoSubmissionRecord $record): string;

    /**
     * Read a previously stored record by id. Returns null if the
     * record is missing.
     */
    public function find(string $id): ?UiFormDemoSubmissionRecord;

    /**
     * Read-only bounded listing of recent submissions, newest first
     * (ORDER BY submitted_at DESC). Used exclusively by the
     * read-only diagnostic admin page in the UiPlayground module.
     *
     * Implementations MUST:
     *   - clamp `$limit` to `[1, MAX_RECENT_LIMIT]` (no unbounded scans);
     *   - return rows sorted newest-first;
     *   - never include hidden columns / tokens / debug — they are
     *     not in the table to begin with, but defence-in-depth
     *     applies.
     *
     * This is the simple "first page only" surface — for the
     * pageable diagnostic listing use {@see paginate()} instead.
     * Kept for ergonomic callers that genuinely want "the latest
     * N rows" without cursor plumbing; equivalent to
     * `paginate(null, $limit)->records`.
     *
     * @return list<UiFormDemoSubmissionRecord>
     */
    public function recent(int $limit = self::DEFAULT_RECENT_LIMIT): array;

    /**
     * Read-only keyset pagination, newest-first
     * (`ORDER BY submitted_at DESC, id DESC`). The `id` tie-breaker
     * makes the ordering deterministic when multiple rows share
     * the same `submitted_at` second (otherwise pagination across
     * those rows could either skip records or repeat them).
     *
     * Semantics:
     *
     *   - `$cursor === null` → first page. Equivalent to
     *     `recent($limit)` but returns the richer
     *     {@see UiFormDemoSubmissionPage} envelope.
     *   - `$cursor !== null` → return rows that come strictly
     *     AFTER the cursor in the newest-first ordering, i.e.
     *     `(submitted_at, id) < (cursor.submittedAt, cursor.id)`
     *     under the same DESC ordering.
     *
     * Implementations MUST:
     *   - clamp `$limit` to `[1, MAX_RECENT_LIMIT]`;
     *   - fetch `$limit + 1` rows to detect `hasMore`, then trim;
     *   - set `nextCursor` to the LAST returned record when
     *     `hasMore` is true and the page is non-empty;
     *   - return `nextCursor === null` when `hasMore` is false (or
     *     the page is empty).
     *
     * The page's `records` are returned in the same readonly
     * `UiFormDemoSubmissionRecord` shape as `recent()`.
     */
    public function paginate(
        ?UiFormDemoSubmissionCursor $cursor = null,
        int $limit = self::DEFAULT_RECENT_LIMIT,
    ): UiFormDemoSubmissionPage;

    /**
     * Read-only keyset pagination with optional bounded search /
     * filter criteria. Same newest-first ordering, same hasMore
     * detection, same clamping — the only difference vs.
     * {@see paginate()} is that the resulting page is restricted
     * to rows matching the criteria.
     *
     * Search semantics:
     *
     *   - `criteria->query !== null` → rows whose `values_json`
     *     contains the term as a substring on the allow-listed
     *     contact fields (`contact_name`, `contact_topic`,
     *     `contact_message`). Implementations MUST parameterise
     *     the term (no raw SQL string concatenation) and MUST
     *     escape LIKE wildcards (`%` / `_`) so a literal `%` in
     *     the query does not turn into a wildcard match. This is
     *     diagnostic-grade search — NOT a full-text engine.
     *   - `criteria->actionName !== null` → rows with the exact
     *     matching `action_name`. The {@see UiFormDemoSubmissionListCriteria}
     *     allow-list keeps this safe.
     *   - When both fields are null, behaves exactly like
     *     `paginate($cursor, $criteria->limit)`.
     *
     * Cursor / filter binding is the HANDLER's responsibility — the
     * repository does NOT inspect the cursor's filter fingerprint.
     * It only uses the cursor's `(submittedAt, id)` boundary.
     */
    public function searchPage(
        UiFormDemoSubmissionListCriteria $criteria,
        ?UiFormDemoSubmissionCursor $cursor = null,
    ): UiFormDemoSubmissionPage;

    /**
     * True when reads / writes are observable across every worker /
     * process sharing this repository — same semantics as the cache
     * variant.
     */
    public function isShared(): bool;

    /**
     * Short human-readable identifier — `"database (driver=mysql)"`,
     * `"in-memory (worker-local)"`, etc. Surfaced in diagnostic
     * payloads only; MUST NOT leak secrets, connection strings, or
     * class FQCNs.
     */
    public function diagnosticName(): string;
}
