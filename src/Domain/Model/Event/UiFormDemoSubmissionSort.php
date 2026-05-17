<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Event;

use Semitexa\PlatformUi\Domain\Exception\UiFormDemoSubmissionSearchException;

/**
 * Server-owned sort decision for the demo-submissions diagnostic
 * grid. Direct parallel to
 * {@see \Semitexa\Modules\UiPlayground\Domain\Model\Event\UiPlaygroundLeadSubmissionSort}
 * (lead grid) — same shape, same algorithm, same allow-list scope.
 *
 * The client may send only a sort TOKEN (e.g. `submittedAt_asc`);
 * this value object maps it through an allow-list to an internal
 * `(field, direction)` pair. The repository never sees the raw
 * token — only `field` + `direction`, both of which come from a
 * fixed, server-controlled set. There is no path from
 * client-supplied input to an `ORDER BY` fragment.
 *
 * Allow-list (this slice — deliberately minimal):
 *
 *   - `submittedAt_desc` (default): `ORDER BY submitted_at DESC, id DESC`
 *   - `submittedAt_asc`:            `ORDER BY submitted_at ASC,  id ASC`
 *
 * `contactName_*` / `contactTopic_*` / `contactMessage_*` / `id_*`
 * tokens are explicitly deferred — sorting on the JSON-encoded
 * `values_json` column would defeat the (submitted_at, id) index,
 * and a second cursor-predicate branch (id-only keyset) would be
 * required for the id variants. This slice stays tight by accepting
 * only the two submittedAt orderings.
 *
 * Unknown tokens throw {@see UiFormDemoSubmissionSearchException}
 * with `reasonCode: invalid_sort`. Empty / null input falls back
 * to {@see DEFAULT_TOKEN}.
 *
 * Cursor binding: the criteria's `fingerprint()` includes the
 * canonical sort token, so a cursor minted under one sort cannot
 * be re-used under another. Cross-sort reuse → handler-level
 * `invalid_cursor` 400.
 */
final readonly class UiFormDemoSubmissionSort
{
    public const FIELD_SUBMITTED_AT = 'submitted_at';

    public const DIRECTION_ASC  = 'asc';
    public const DIRECTION_DESC = 'desc';

    public const TOKEN_SUBMITTED_AT_DESC = 'submittedAt_desc';
    public const TOKEN_SUBMITTED_AT_ASC  = 'submittedAt_asc';

    public const DEFAULT_TOKEN = self::TOKEN_SUBMITTED_AT_DESC;

    /**
     * Closed allow-list of accepted client tokens. Adding a token
     * here is a deliberate slice — it requires a matching cursor-
     * predicate + comparator branch in the repositories.
     *
     * @var array<string, array{field:string, direction:string}>
     */
    private const ALLOWED_TOKENS = [
        self::TOKEN_SUBMITTED_AT_DESC => ['field' => self::FIELD_SUBMITTED_AT, 'direction' => self::DIRECTION_DESC],
        self::TOKEN_SUBMITTED_AT_ASC  => ['field' => self::FIELD_SUBMITTED_AT, 'direction' => self::DIRECTION_ASC],
    ];

    private function __construct(
        public string $token,
        public string $field,
        public string $direction,
    ) {}

    /**
     * Build a sort decision from raw request input.
     *
     * @throws UiFormDemoSubmissionSearchException invalid_sort
     */
    public static function fromRequest(?string $raw): self
    {
        if ($raw === null || $raw === '') {
            return self::default();
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return self::default();
        }
        if (!isset(self::ALLOWED_TOKENS[$trimmed])) {
            throw UiFormDemoSubmissionSearchException::invalidSort();
        }
        $entry = self::ALLOWED_TOKENS[$trimmed];
        return new self(
            token:     $trimmed,
            field:     $entry['field'],
            direction: $entry['direction'],
        );
    }

    public static function default(): self
    {
        $entry = self::ALLOWED_TOKENS[self::DEFAULT_TOKEN];
        return new self(
            token:     self::DEFAULT_TOKEN,
            field:     $entry['field'],
            direction: $entry['direction'],
        );
    }

    /**
     * True when this sort is the documented default. Used by the
     * criteria's `isDefault()` so the page handler can keep the
     * legacy `paginate()` path for the default state.
     */
    public function isDefault(): bool
    {
        return $this->token === self::DEFAULT_TOKEN;
    }

    /**
     * Allow-list snapshot for tests + documentation. Returned by
     * value (the const itself is private).
     *
     * @return list<string>
     */
    public static function allowedTokens(): array
    {
        return array_keys(self::ALLOWED_TOKENS);
    }
}
