<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Event;

use Semitexa\PlatformUi\Application\Service\Submit\Action\PlatformDemoStoreContactDbAction;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormDatabaseDemoSubmissionRepositoryInterface;
use Semitexa\PlatformUi\Domain\Exception\UiFormDemoSubmissionSearchException;

/**
 * Read-only search/filter inputs for the diagnostic listing.
 *
 * Built from raw request strings via {@see fromRequest()} — the
 * factory normalises the values once (trim + empty→null + length
 * + allow-list checks) so the rest of the pipeline can treat the
 * criteria as already-validated.
 *
 * Trust perimeter:
 *
 *   - `query` is treated as DATA. The repository binds it through
 *     parameter placeholders and escapes LIKE wildcards (`%` / `_`)
 *     itself — this object only stores the canonical string.
 *   - `actionName` is restricted to the allow-list below. Unknown
 *     values throw at construction time. The diagnostic listing
 *     reads from the DB-backed table, so the allow-list contains
 *     ONLY action names that can appear in `platform_ui_demo_submissions`.
 *   - `limit` is clamped to [1, MAX_RECENT_LIMIT] mirroring
 *     paginate()'s contract.
 *
 * The {@see fingerprint()} method produces a stable opaque token
 * the cursor uses to refuse reuse across different filter
 * combinations (operator probes that splice cursors from one
 * filter onto another are rejected at cursor-decode time).
 */
final readonly class UiFormDemoSubmissionListCriteria
{
    /**
     * Bounded maximum length for the user-supplied search term.
     * Above this we reject with `invalid_search_query` rather than
     * silently truncating — silent truncation would let an
     * arbitrarily long input produce the same fingerprint as a
     * shorter one and break cursor-reuse rejection.
     */
    public const MAX_QUERY_LENGTH = 100;

    /**
     * Allow-listed action names. The diagnostic listing only
     * surfaces DB-backed rows, so the cache-only action's name is
     * deliberately NOT in this list — supplying it would resolve
     * to "no rows" via a different surface and operator confusion
     * isn't worth the symmetry.
     *
     * @var list<string>
     */
    public const ALLOWED_ACTION_NAMES = [
        PlatformDemoStoreContactDbAction::NAME,
    ];

    private function __construct(
        public ?string $query,
        public ?string $actionName,
        public int $limit,
        public UiFormDemoSubmissionSort $sort,
    ) {}

    /**
     * Build canonical criteria from raw request input. Raw values
     * here are STRINGS straight off the query string — the factory
     * normalises (trim, empty→null), validates (length, allow-list),
     * and clamps the limit. Failures throw the typed search
     * exception; the handler turns them into the safe 400 template
     * branch.
     *
     * @throws UiFormDemoSubmissionSearchException on invalid input.
     */
    public static function fromRequest(
        ?string $rawQuery,
        ?string $rawAction,
        ?string $rawLimit,
        ?string $rawSort = null,
    ): self {
        return new self(
            query:      self::normaliseQuery($rawQuery),
            actionName: self::normaliseAction($rawAction),
            limit:      self::clampLimit($rawLimit),
            sort:       UiFormDemoSubmissionSort::fromRequest($rawSort),
        );
    }

    /**
     * True when no q AND no actionName are active. Used by the
     * template only to render the "Filtering by …" copy — for
     * cursor/repo routing prefer {@see isDefault()} which ALSO
     * checks the sort is the documented default.
     */
    public function isUnfiltered(): bool
    {
        return $this->query === null && $this->actionName === null;
    }

    /**
     * True when q is null AND action is null AND sort is the
     * documented default (`submittedAt_desc`).
     *
     * The handler routes to the legacy `paginate()` repo method
     * only when this is true; otherwise it calls `searchPage()`
     * with the full criteria so the sort direction is honoured.
     *
     * The cursor's `filterFingerprint` is `null` in exactly the
     * same condition — so a v1 (no `f`) cursor remains accepted
     * for the default unfiltered unsorted state.
     */
    public function isDefault(): bool
    {
        return $this->query === null
            && $this->actionName === null
            && $this->sort->isDefault();
    }

    /**
     * Produce the stable fingerprint the cursor binds to.
     *
     *   - Default unfiltered + default sort → `null` (cursor
     *     carries no `f`; back-compatible with v1 cursors).
     *   - Otherwise → first 16 hex chars of
     *     `sha256(query|action|sortToken)` over the canonical
     *     case-folded form.
     *
     * Why first-16 only: the cursor is already shape-validated
     * + opaque, the fingerprint is a tamper-resistance prefix
     * (not a secret), and 64 bits of entropy is far more than
     * enough to make accidental cross-filter / cross-sort cursor
     * reuse collision-implausible.
     */
    public function fingerprint(): ?string
    {
        if ($this->isDefault()) {
            return null;
        }
        $canonical =
            mb_strtolower((string) $this->query, 'UTF-8')
            . '|'
            . ((string) $this->actionName)
            . '|'
            . $this->sort->token;
        return substr(hash('sha256', $canonical), 0, 16);
    }

    private static function normaliseQuery(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }
        if (mb_strlen($trimmed, 'UTF-8') > self::MAX_QUERY_LENGTH) {
            throw UiFormDemoSubmissionSearchException::invalidQuery();
        }
        return $trimmed;
    }

    private static function normaliseAction(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }
        if (!in_array($trimmed, self::ALLOWED_ACTION_NAMES, true)) {
            throw UiFormDemoSubmissionSearchException::invalidAction();
        }
        return $trimmed;
    }

    private static function clampLimit(?string $raw): int
    {
        $default = UiFormDatabaseDemoSubmissionRepositoryInterface::DEFAULT_RECENT_LIMIT;
        $max     = UiFormDatabaseDemoSubmissionRepositoryInterface::MAX_RECENT_LIMIT;
        if ($raw === null || $raw === '' || preg_match('/\A\d+\z/', $raw) !== 1) {
            return $default;
        }
        return max(1, min((int) $raw, $max));
    }
}
