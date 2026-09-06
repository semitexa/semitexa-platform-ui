<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Db\MySQL\Repository;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Orm\Application\Service\OrmBackedStore;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Direction;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\PlatformUi\Application\Db\MySQL\Model\UiFormDemoSubmissionResource;
use Semitexa\PlatformUi\Domain\Model\UiFormDemoSubmission;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormDatabaseDemoSubmissionRepositoryInterface;
use Semitexa\PlatformUi\Domain\Exception\UiFormDemoSubmissionCursorException;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormDemoSubmissionCursor;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormDemoSubmissionListCriteria;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormDemoSubmissionPage;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormDemoSubmissionRecord;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormDemoSubmissionSort;

/**
 * Database-backed demo submission repository — the production default
 * for {@see UiFormDatabaseDemoSubmissionRepositoryInterface}.
 *
 * Mirrors the scheduler / webhooks / workflow repository pattern in
 * this codebase: `#[SatisfiesRepositoryContract]`, OrmManager via
 * `#[InjectAsReadonly]`, lazy DomainRepository memoisation.
 *
 * Storage perimeter (re-asserted by the action's allow-list AND the
 * repository's own write path):
 *
 *   - Only the four logical columns land in the DB:
 *     `form_instance_id`, `action_name`, `submitted_at`, `values_json`.
 *   - `values_json` is `json_encode()` output of the action's
 *     allow-listed values map. No tokens, no ctx, no debug.
 *   - The id is supplied by the action verbatim (`uifs_<16hex>`);
 *     the ORM's `manual` PK strategy keeps it from being overwritten.
 *   - Timestamps come from `HasTimestamps` (created_at / updated_at)
 *     so the table has both the user-visible `submitted_at` and the
 *     standard audit columns the rest of the project relies on.
 *
 * Find path is for tests + future safe diagnostics; never wired to
 * HTTP today.
 */
#[SatisfiesRepositoryContract(of: UiFormDatabaseDemoSubmissionRepositoryInterface::class)]
final class UiFormDemoSubmissionDbRepository implements UiFormDatabaseDemoSubmissionRepositoryInterface
{
    use OrmBackedStore;

    #[InjectAsReadonly]
    protected OrmManager $orm;

    public function save(UiFormDemoSubmissionRecord $record): string
    {
        $this->repository()->insert(self::toSubmission($record));
        return $record->id;
    }

    public function find(string $id): ?UiFormDemoSubmissionRecord
    {
        /** @var UiFormDemoSubmission|null $submission */
        $submission = $this->repository()->query()
            ->where(UiFormDemoSubmissionResource::column('id'), Operator::Equals, $id)
            ->fetchOneAs(UiFormDemoSubmission::class, $this->mapperRegistry());
        if ($submission === null) {
            return null;
        }
        return self::toRecord($submission);
    }

    public function recent(int $limit = UiFormDatabaseDemoSubmissionRepositoryInterface::DEFAULT_RECENT_LIMIT): array
    {
        return $this->paginate(null, $limit)->records;
    }

    public function paginate(
        ?UiFormDemoSubmissionCursor $cursor = null,
        int $limit = UiFormDatabaseDemoSubmissionRepositoryInterface::DEFAULT_RECENT_LIMIT,
    ): UiFormDemoSubmissionPage {
        return $this->runQuery(null, $cursor, $limit);
    }

    public function searchPage(
        UiFormDemoSubmissionListCriteria $criteria,
        ?UiFormDemoSubmissionCursor $cursor = null,
    ): UiFormDemoSubmissionPage {
        return $this->runQuery($criteria, $cursor, $criteria->limit);
    }

    /**
     * Shared sort + keyset + (optional) filter + fetch path.
     *
     *   - `$criteria === null` → legacy unfiltered listing
     *     ({@see paginate()}). No `values_json` LIKE, no
     *     `action_name` predicate. Default `submittedAt_desc`
     *     ordering — same as before the sort slice.
     *   - `$criteria !== null` → diagnostic search/filter +
     *     server-owned sort. Bound LIKE parameter against
     *     `values_json` (wildcards in the user input escaped),
     *     optional `action_name` equality, sort direction from
     *     the criteria's `UiFormDemoSubmissionSort` value object.
     *     The sort direction is NEVER read from the request
     *     directly — only from the allow-listed sort token
     *     resolved server-side.
     */
    private function runQuery(
        ?UiFormDemoSubmissionListCriteria $criteria,
        ?UiFormDemoSubmissionCursor $cursor,
        int $limit,
    ): UiFormDemoSubmissionPage {
        self::assertCursorMatchesCriteria($criteria, $cursor);

        $clamped = max(1, min($limit, UiFormDatabaseDemoSubmissionRepositoryInterface::MAX_RECENT_LIMIT));

        // Resolve sort direction. `paginate()` (criteria=null) keeps
        // the legacy DESC default unchanged.
        $sortAscending = $criteria !== null
            && $criteria->sort->direction === UiFormDemoSubmissionSort::DIRECTION_ASC;
        $orderDirection = $sortAscending ? Direction::Asc : Direction::Desc;

        $query = $this->repository()->query()
            ->orderBy(UiFormDemoSubmissionResource::column('submitted_at'), $orderDirection)
            ->orderBy(UiFormDemoSubmissionResource::column('id'), $orderDirection)
            // Fetch one extra row so we can detect hasMore cheaply.
            ->limit($clamped + 1);

        if ($cursor !== null) {
            // Keyset predicate: rows strictly past the cursor in
            // the ACTIVE ordering. Direction flips the comparison.
            //
            // Expressed as a SARGable OR pair so MySQL can still
            // use the (submitted_at, id) index when planning.
            $cursorTs = (new \DateTimeImmutable())->setTimestamp($cursor->submittedAt)->format('Y-m-d H:i:s');
            if ($sortAscending) {
                $query->whereRaw(
                    '(`submitted_at` > ? OR (`submitted_at` = ? AND `id` > ?))',
                    [$cursorTs, $cursorTs, $cursor->id],
                );
            } else {
                $query->whereRaw(
                    '(`submitted_at` < ? OR (`submitted_at` = ? AND `id` < ?))',
                    [$cursorTs, $cursorTs, $cursor->id],
                );
            }
        }

        if ($criteria !== null) {
            if ($criteria->actionName !== null) {
                $query->where(
                    UiFormDemoSubmissionResource::column('action_name'),
                    Operator::Equals,
                    $criteria->actionName,
                );
            }
            if ($criteria->query !== null) {
                // Bound LIKE against the serialised JSON column.
                // We escape `%` / `_` / the escape char itself in
                // the user input so a literal `%` in the search
                // term does NOT turn into a wildcard. The query
                // string is passed as a parameter — never spliced
                // into SQL.
                $pattern = '%' . self::escapeLike($criteria->query) . '%';
                $query->whereRaw(
                    "`values_json` LIKE ? ESCAPE '\\\\'",
                    [$pattern],
                );
            }
        }

        /** @var list<UiFormDemoSubmission> $submissions */
        $submissions = $query->fetchAllAs(
            UiFormDemoSubmission::class,
            $this->mapperRegistry(),
        );

        $hasMore = count($submissions) > $clamped;
        if ($hasMore) {
            $submissions = array_slice($submissions, 0, $clamped);
        }

        $records = [];
        foreach ($submissions as $submission) {
            $records[] = self::toRecord($submission);
        }

        $nextCursor = ($hasMore && $records !== [])
            ? new UiFormDemoSubmissionCursor(
                submittedAt:       $records[array_key_last($records)]->submittedAt,
                id:                $records[array_key_last($records)]->id,
                filterFingerprint: $criteria?->fingerprint(),
            )
            : null;

        return new UiFormDemoSubmissionPage(
            records:    $records,
            nextCursor: $nextCursor,
            limit:      $clamped,
            hasMore:    $hasMore,
        );
    }

    /**
     * Escape SQL LIKE special characters in user-supplied input.
     * The `\` escape character is itself escaped first; otherwise
     * a trailing `\` in the user input could escape the closing
     * `%` of the bound pattern.
     */
    private static function escapeLike(string $term): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $term,
        );
    }

    public function isShared(): bool
    {
        // Production ORM connection pools are process-shared by design;
        // the database itself is the canonical shared store.
        return true;
    }

    public function diagnosticName(): string
    {
        return 'database (driver=mysql)';
    }

    private function repository(): DomainRepository
    {
        return $this->domainRepository(UiFormDemoSubmissionResource::class, UiFormDemoSubmission::class);
    }

    private static function toSubmission(UiFormDemoSubmissionRecord $record): UiFormDemoSubmission
    {
        return new UiFormDemoSubmission(
            id:             $record->id,
            formInstanceId: $record->formInstanceId,
            actionName:     $record->actionName,
            submittedAt:    (new \DateTimeImmutable())->setTimestamp($record->submittedAt),
            values:         self::assertStorableValues($record->values, $record->id),
        );
    }

    /**
     * The record PROMISES string keys carrying scalars or null; being a plain
     * public array, nothing enforced it. A caller that slipped a nested value
     * past it wrote a submission the read path could not rebuild — toRecord()
     * threw, and one bad row took the whole listing down with it. Refuse the
     * write instead, where the caller is still there to be told.
     *
     * Typed loosely on purpose: the record's own `array<string, scalar|null>`
     * is the promise being checked, so taking it at its word here would make
     * the check unreachable to a static reader and useless to a live one.
     *
     * @param array<array-key, mixed> $values
     * @return array<string, scalar|null>
     */
    private static function assertStorableValues(array $values, string $id): array
    {
        $storable = [];
        foreach ($values as $key => $value) {
            if (!is_string($key) || (!is_scalar($value) && $value !== null)) {
                throw new \InvalidArgumentException(
                    'Demo submission ' . $id . ' carries a value that is not a scalar or null.',
                );
            }
            $storable[$key] = $value;
        }

        return $storable;
    }

    /**
     * The mapper already turned `values_json` into an array; the record is
     * narrower than that — only string keys carrying scalars or null — so the
     * shape is re-asserted here, at the boundary that promises it.
     */
    private static function toRecord(UiFormDemoSubmission $submission): UiFormDemoSubmissionRecord
    {
        $values = [];
        foreach ($submission->getValues() as $key => $value) {
            if (!is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException(
                    'Malformed values_json for demo submission ' . $submission->getId() . '.',
                );
            }
            $values[$key] = $value;
        }

        return new UiFormDemoSubmissionRecord(
            id:             $submission->getId(),
            formInstanceId: $submission->getFormInstanceId(),
            actionName:     $submission->getActionName(),
            submittedAt:    $submission->getSubmittedAt()->getTimestamp(),
            values:         $values,
        );
    }

    private static function assertCursorMatchesCriteria(
        ?UiFormDemoSubmissionListCriteria $criteria,
        ?UiFormDemoSubmissionCursor $cursor,
    ): void {
        if ($cursor === null) {
            return;
        }
        if ($cursor->filterFingerprint !== $criteria?->fingerprint()) {
            throw new UiFormDemoSubmissionCursorException(
                'Cursor fingerprint does not match active criteria.',
            );
        }
    }
}
