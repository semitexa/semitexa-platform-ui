<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Event;

use Semitexa\PlatformUi\Domain\Exception\UiFormDemoSubmissionCursorException;

/**
 * Opaque keyset-pagination cursor for the read-only diagnostic
 * listing of database-backed demo submissions.
 *
 * Wire format: `base64url(json_encode({"s": <int>, "i": "<id>" [, "f": "<16hex>"]}))`.
 *
 *   - `s` : `submittedAt` Unix timestamp (int) of the boundary row.
 *   - `i` : `id` of the boundary row (`uifs_<16hex>`).
 *   - `f` : OPTIONAL filter fingerprint. Present only when the
 *           cursor was generated for a filtered listing (q and/or
 *           action). Stable 16-hex prefix of `sha256(query|action)`
 *           over the canonical case-folded form (see
 *           {@see UiFormDemoSubmissionListCriteria::fingerprint()}).
 *
 * The cursor is OPAQUE to clients. Decoding intentionally never
 * uses `serialize`/`unserialize`, never `eval`s anything, and never
 * decodes more than a tiny, shape-validated JSON object. Anything
 * outside the documented shape — bad base64, bad JSON, missing /
 * extra keys, wrong types, unsafe id pattern — throws
 * {@see UiFormDemoSubmissionCursorException} (`reasonCode:
 * invalid_cursor`).
 *
 * Why it doesn't need to be signed:
 *
 *   - The cursor carries ONLY id + timestamp + (optionally) a
 *     filter fingerprint from the diagnostic listing's existing
 *     public surface (the listing already shows the same id +
 *     timestamp in the rendered HTML; the fingerprint is a derived
 *     prefix, not user-secret data).
 *   - Forging a cursor cannot reach hidden rows: the diagnostic
 *     route shows the same allow-listed projection regardless of
 *     where the keyset boundary is set.
 *   - The id pattern is validated to the same safe `uifs_<16hex>`
 *     regex the action emits.
 *
 * Filter fingerprint contract:
 *
 *   - A cursor generated for filter `F` must only be reused with
 *     filter `F`. Mismatch → invalid_cursor at handler-gate level.
 *   - A cursor generated for the unfiltered listing omits `f`.
 *     It is valid only for unfiltered requests. Sending it with
 *     a filter → invalid_cursor.
 *   - Old 2-key cursors (no `f`) remain accepted by the decoder
 *     so the no-filter pagination path stays backward-compatible.
 *
 * If a future slice exposes the cursor to less-trusted clients or
 * adds permission-scoped filtering, the cursor SHOULD be signed
 * with the SignedContext helper. For now, opaque + shape-validated
 * is the right trade-off.
 */
final readonly class UiFormDemoSubmissionCursor
{
    /** Same shape `PlatformDemoStoreContactDbAction` emits. */
    private const SAFE_ID_PATTERN = '/\Auifs_[a-f0-9]{16}\z/';

    /** Filter fingerprint shape — 16-hex prefix of a sha256. */
    private const SAFE_FILTER_FINGERPRINT_PATTERN = '/\A[a-f0-9]{16}\z/';

    public function __construct(
        public int $submittedAt,
        public string $id,
        public ?string $filterFingerprint = null,
    ) {
        if ($submittedAt < 0) {
            throw new UiFormDemoSubmissionCursorException();
        }
        if (preg_match(self::SAFE_ID_PATTERN, $id) !== 1) {
            throw new UiFormDemoSubmissionCursorException();
        }
        if ($filterFingerprint !== null
            && preg_match(self::SAFE_FILTER_FINGERPRINT_PATTERN, $filterFingerprint) !== 1
        ) {
            throw new UiFormDemoSubmissionCursorException();
        }
    }

    public function encode(): string
    {
        $payload = ['s' => $this->submittedAt, 'i' => $this->id];
        if ($this->filterFingerprint !== null) {
            $payload['f'] = $this->filterFingerprint;
        }
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        // base64url (RFC 4648 §5). No `+` / `/` / `=` so the cursor
        // survives a query string without escaping.
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * Decode a wire cursor. Throws {@see UiFormDemoSubmissionCursorException}
     * on any deviation from the documented shape — bad base64, bad
     * JSON, wrong keys, wrong types, unsafe id, anything.
     */
    public static function decode(string $encoded): self
    {
        $encoded = trim($encoded);
        if ($encoded === '') {
            throw new UiFormDemoSubmissionCursorException();
        }
        // Reject anything outside base64url's alphabet up front;
        // base64_decode(strict=true) would also catch it but a
        // tight regex makes the rejection earlier + cheaper.
        if (preg_match('/\A[A-Za-z0-9_\-]+\z/', $encoded) !== 1) {
            throw new UiFormDemoSubmissionCursorException();
        }
        $padded = $encoded . str_repeat('=', (4 - (strlen($encoded) % 4)) % 4);
        $base64 = strtr($padded, '-_', '+/');
        $json = base64_decode($base64, true);
        if ($json === false) {
            throw new UiFormDemoSubmissionCursorException();
        }
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new UiFormDemoSubmissionCursorException();
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new UiFormDemoSubmissionCursorException();
        }
        // Tight key whitelist. Two valid shapes:
        //   - {s, i}      (no filter; back-compat with v1 cursors)
        //   - {s, i, f}   (filtered listing)
        // Anything else — missing required key, extra unknown key
        // — is a smuggling attempt or a future-cursor variant we
        // haven't reviewed.
        $keys = array_keys($decoded);
        sort($keys);
        if ($keys !== ['i', 's'] && $keys !== ['f', 'i', 's']) {
            throw new UiFormDemoSubmissionCursorException();
        }
        $submittedAt = $decoded['s'] ?? null;
        $id = $decoded['i'] ?? null;
        $filterFingerprint = $decoded['f'] ?? null;
        if (!is_int($submittedAt) || !is_string($id)) {
            throw new UiFormDemoSubmissionCursorException();
        }
        if ($filterFingerprint !== null && !is_string($filterFingerprint)) {
            throw new UiFormDemoSubmissionCursorException();
        }
        // Constructor will run its own validation (id pattern,
        // fingerprint pattern, non-negative submittedAt). Letting
        // it throw keeps the rules in one place.
        return new self($submittedAt, $id, $filterFingerprint);
    }

    /**
     * Convenience: return `null` for missing / empty input instead
     * of throwing. The handler uses this when reading a missing
     * query parameter — only a non-empty, malformed cursor is a
     * 400 condition.
     */
    public static function tryFromString(?string $encoded): ?self
    {
        if ($encoded === null) {
            return null;
        }
        $trimmed = trim($encoded);
        if ($trimmed === '') {
            return null;
        }
        return self::decode($trimmed);
    }
}
