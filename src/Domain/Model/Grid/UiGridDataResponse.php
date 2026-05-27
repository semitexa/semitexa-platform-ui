<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Grid;

/**
 * Shared envelope contract for every `platform.grid` data endpoint.
 *
 * The `platform.grid` runtime
 * ({@see \Semitexa\PlatformUi\Application\Static\js\grid-runtime.js})
 * expects exactly two JSON shapes on the wire:
 *
 *   Success (HTTP 200):
 *     {
 *       "ok":         true,
 *       "gridId":     "<stable-id>",
 *       "rows":       [<row-projection>, …],
 *       "pagination": { "limit": int, "hasMore": bool, "nextCursor": ?string },
 *       "filters":    { "q": ?string, "action": ?string }
 *     }
 *
 *   Error (HTTP 400/403):
 *     { "ok": false, "reason": "<stable-code>", "message": "<safe-message>" }
 *
 * This class is the single source of truth for that shape. Both
 * existing data endpoints
 * ({@see \Semitexa\Modules\UiPlayground\Application\Handler\PayloadHandler\LeadAdminGridDataHandler},
 * {@see \Semitexa\Modules\UiPlayground\Application\Handler\PayloadHandler\DemoSubmissionsAdminGridDataHandler})
 * call into it; any future grid-data endpoint MUST do the same so
 * the runtime's shape contract cannot drift.
 *
 * Scope:
 *
 *   - This class ONLY shapes the envelope. It does NOT authorize, query,
 *     parse criteria, manage cursors, or project rows. Those concerns
 *     stay in each handler so an authorizer / criteria / projection
 *     change does not require touching this class.
 *   - This class returns plain `array` envelopes; the calling handler
 *     is responsible for `json_encode()`, setting `Content-Type:
 *     application/json; charset=utf-8`, and choosing the HTTP status.
 *     Threading the response object through here would tie the
 *     contract to a specific HTTP layer.
 *   - The error factory accepts `reason` + `message` as
 *     handler-supplied strings. It does NOT decide which value to
 *     echo — the handler picks safe / non-leaking values.
 *
 * Trust perimeter (re-asserted):
 *
 *   - The handler MUST sanitise / project rows before passing them
 *     to {@see success()}. Passing raw `values_json`, tokens, ctx,
 *     dispatchId, debug internals, or class FQCNs would leak them
 *     verbatim — this class does NOT inspect row contents.
 *   - The handler MUST keep `gridId` to a stable, server-owned
 *     identifier (`platform-ui.<…>` / `ui-playground.<…>` /
 *     similar). The class does NOT validate `gridId`.
 *
 * Pure utility; not a service. Static factory.
 */
final class UiGridDataResponse
{
    /**
     * Build a success envelope. The output array's KEY ORDER is
     * pinned (`ok`, `gridId`, `rows`, `pagination`, `filters`) so
     * the on-wire JSON stays byte-stable across PHP versions and
     * future refactors.
     *
     * @param list<array<string, mixed>> $rows  Already-projected,
     *      already-sanitised row maps. The handler owns row
     *      shape; this class does not inspect keys.
     * @return array{
     *   ok: true,
     *   gridId: string,
     *   rows: list<array<string, mixed>>,
     *   pagination: array<string, mixed>,
     *   filters: array{q:?string, action:?string},
     * }
     */
    public static function success(
        string $gridId,
        array $rows,
        UiGridPaginationPayload $pagination,
        UiGridFilterState $filters,
    ): array {
        return [
            'ok'         => true,
            'gridId'     => $gridId,
            'rows'       => $rows,
            'pagination' => $pagination->toArray(),
            'filters'    => $filters->toArray(),
        ];
    }

    /**
     * Build an error envelope. The output array's KEY ORDER is
     * pinned (`ok`, `reason`, `message`).
     *
     * @return array{ ok: false, reason: string, message: string }
     */
    public static function error(string $reason, string $message): array
    {
        return [
            'ok'      => false,
            'reason'  => $reason,
            'message' => $message,
        ];
    }
}
