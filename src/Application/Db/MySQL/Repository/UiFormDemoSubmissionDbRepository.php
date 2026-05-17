<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Db\MySQL\Repository;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Direction;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\PlatformUi\Application\Db\MySQL\Model\UiFormDemoSubmissionResource;
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
    #[InjectAsReadonly]
    protected OrmManager $orm;

    private ?DomainRepository $repository = null;

    /**
     * Test seam — production path uses property injection.
     */
    public function withOrmManager(OrmManager $orm): self
    {
        $this->orm = $orm;
        $this->repository = null;
        return $this;
    }

    public function save(UiFormDemoSubmissionRecord $record): string
    {
        $resource = self::toResource($record);
        $this->repository()->insert($resource);
        return $record->id;
    }

    public function find(string $id): ?UiFormDemoSubmissionRecord
    {
        /** @var UiFormDemoSubmissionResource|null $resource */
        $resource = $this->repository()->query()
            ->where(UiFormDemoSubmissionResource::column('id'), Operator::Equals, $id)
            ->fetchOneAs(UiFormDemoSubmissionResource::class, $this->orm()->getMapperRegistry());
        if ($resource === null) {
            return null;
        }
        return self::toRecord($resource);
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

        /** @var list<UiFormDemoSubmissionResource> $resources */
        $resources = $query->fetchAllAs(
            UiFormDemoSubmissionResource::class,
            $this->orm()->getMapperRegistry(),
        );

        $hasMore = count($resources) > $clamped;
        if ($hasMore) {
            $resources = array_slice($resources, 0, $clamped);
        }

        $records = [];
        foreach ($resources as $resource) {
            $records[] = self::toRecord($resource);
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
        return $this->repository ??= $this->orm()->repository(
            UiFormDemoSubmissionResource::class,
            UiFormDemoSubmissionResource::class,
        );
    }

    /**
     * Mirrors the scheduler / webhooks / workflow repository
     * pattern — lazy-init OrmManager so a path that constructs
     * the repository directly (no DI; e.g. some bootstrap
     * fallbacks) still has a usable manager. Production wiring
     * fills `$this->orm` via property injection BEFORE this
     * accessor runs, so the `??=` keeps the injected instance
     * intact.
     */
    private function orm(): OrmManager
    {
        return $this->orm ??= new OrmManager();
    }

    private static function toResource(UiFormDemoSubmissionRecord $record): UiFormDemoSubmissionResource
    {
        return new UiFormDemoSubmissionResource(
            id:               $record->id,
            form_instance_id: $record->formInstanceId,
            action_name:      $record->actionName,
            submitted_at:     (new \DateTimeImmutable())->setTimestamp($record->submittedAt),
            values_json:      json_encode(
                $record->values,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ),
        );
    }

    private static function toRecord(UiFormDemoSubmissionResource $resource): UiFormDemoSubmissionRecord
    {
        $values = [];
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($resource->values_json, true, 8, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    if (!is_string($key) || (!is_scalar($value) && $value !== null)) {
                        throw new \InvalidArgumentException(
                            'Malformed values_json for demo submission resource ' . $resource->id . '.',
                        );
                    }
                    $values[$key] = $value;
                }
            }
        } catch (\JsonException) {
            // Corrupted row — return an empty values map rather than
            // surface the parse error; the diagnostic surface stays
            // safe + uniform.
            $values = [];
        }
        return new UiFormDemoSubmissionRecord(
            id:             $resource->id,
            formInstanceId: $resource->form_instance_id,
            actionName:     $resource->action_name,
            submittedAt:    $resource->submitted_at->getTimestamp(),
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
