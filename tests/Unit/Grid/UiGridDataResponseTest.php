<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Grid;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Domain\Model\Grid\UiGridDataResponse;
use Semitexa\PlatformUi\Domain\Model\Grid\UiGridFilterState;
use Semitexa\PlatformUi\Domain\Model\Grid\UiGridPaginationData;

/**
 * Pin the on-wire envelope shape that the package `platform.grid`
 * runtime consumes.
 *
 * Both current grid-data endpoints
 * ({@see \Semitexa\Modules\UiPlayground\Application\Handler\PayloadHandler\LeadAdminGridDataHandler},
 * {@see \Semitexa\Modules\UiPlayground\Application\Handler\PayloadHandler\DemoSubmissionsAdminGridDataHandler})
 * call into this helper. A future handler that drifts from this
 * shape would silently break the runtime; this test is the
 * canonical canary.
 *
 * Two things this test does NOT assert:
 *
 *   - end-to-end JSON output bytes — the existing integration
 *     tests on each handler already exercise the JSON envelope
 *     after `json_encode` and through the response object;
 *   - row contents — row projection is the handler's job, not
 *     the helper's.
 */
final class UiGridDataResponseTest extends TestCase
{
    // -------------------------------------------------------------
    // success() — exact key list + key order pinned.
    // -------------------------------------------------------------

    #[Test]
    public function success_envelope_has_exact_top_level_keys_in_order(): void
    {
        $envelope = UiGridDataResponse::success(
            gridId:     'platform-ui.demo-submissions',
            rows:       [],
            pagination: new UiGridPaginationData(25, false, null),
            filters:    new UiGridFilterState(null, null),
        );
        self::assertSame(['ok', 'gridId', 'rows', 'pagination', 'filters'], array_keys($envelope));
    }

    #[Test]
    public function success_ok_is_true(): void
    {
        $envelope = UiGridDataResponse::success(
            'g', [], new UiGridPaginationData(1, false, null), new UiGridFilterState(null, null),
        );
        self::assertTrue($envelope['ok']);
    }

    #[Test]
    public function success_preserves_grid_id_verbatim(): void
    {
        $envelope = UiGridDataResponse::success(
            'platform-ui.demo-submissions',
            [],
            new UiGridPaginationData(1, false, null),
            new UiGridFilterState(null, null),
        );
        self::assertSame('platform-ui.demo-submissions', $envelope['gridId']);
    }

    #[Test]
    public function success_preserves_rows_verbatim(): void
    {
        $rows = [
            ['id' => 'uifs_aaaa', 'leadName' => 'Ada'],
            ['id' => 'uifs_bbbb', 'leadName' => 'Bea'],
        ];
        $envelope = UiGridDataResponse::success(
            'g', $rows,
            new UiGridPaginationData(2, true, 'CURSORX'),
            new UiGridFilterState('ada', null),
        );
        self::assertSame($rows, $envelope['rows']);
    }

    #[Test]
    public function success_pagination_has_exact_keys_in_order(): void
    {
        $envelope = UiGridDataResponse::success(
            'g', [],
            new UiGridPaginationData(25, true, 'CURSORX'),
            new UiGridFilterState(null, null),
        );
        self::assertSame(['limit', 'hasMore', 'nextCursor'], array_keys($envelope['pagination']));
        self::assertSame(25,        $envelope['pagination']['limit']);
        self::assertSame(true,      $envelope['pagination']['hasMore']);
        self::assertSame('CURSORX', $envelope['pagination']['nextCursor']);
    }

    #[Test]
    public function success_filters_have_exact_keys_in_order(): void
    {
        $envelope = UiGridDataResponse::success(
            'g', [],
            new UiGridPaginationData(1, false, null),
            new UiGridFilterState('hello', 'platform.demo.storeContactDb'),
        );
        self::assertSame(['q', 'action'], array_keys($envelope['filters']));
        self::assertSame('hello',                        $envelope['filters']['q']);
        self::assertSame('platform.demo.storeContactDb', $envelope['filters']['action']);
    }

    #[Test]
    public function success_does_not_add_meta_timestamp_or_debug(): void
    {
        $envelope = UiGridDataResponse::success(
            'g', [],
            new UiGridPaginationData(1, false, null),
            new UiGridFilterState(null, null),
        );
        // Pin perimeter — the runtime parses ONLY the documented
        // five top-level keys, and any additional key would risk
        // smuggling operator-internal metadata through the
        // diagnostic surface.
        foreach (['meta', 'timestamp', 'debug', 'version', 'endpoint'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $envelope);
        }
    }

    #[Test]
    public function success_does_not_inspect_or_modify_row_contents(): void
    {
        // Whatever the handler hands in, the helper passes through.
        // Row projection is the handler's responsibility — the
        // helper deliberately stays oblivious.
        $weirdRows = [
            ['unusual_key' => 'whatever'],
            ['nested' => ['shape']],
        ];
        $envelope = UiGridDataResponse::success(
            'g', $weirdRows,
            new UiGridPaginationData(1, false, null),
            new UiGridFilterState(null, null),
        );
        self::assertSame($weirdRows, $envelope['rows']);
    }

    // -------------------------------------------------------------
    // error() — exact key list + key order pinned.
    // -------------------------------------------------------------

    #[Test]
    public function error_envelope_has_exact_top_level_keys_in_order(): void
    {
        $envelope = UiGridDataResponse::error('invalid_cursor', 'Pagination cursor is invalid.');
        self::assertSame(['ok', 'reason', 'message'], array_keys($envelope));
    }

    #[Test]
    public function error_ok_is_false(): void
    {
        $envelope = UiGridDataResponse::error('invalid_cursor', 'msg');
        self::assertFalse($envelope['ok']);
    }

    #[Test]
    public function error_preserves_reason_and_message_verbatim(): void
    {
        $envelope = UiGridDataResponse::error('invalid_search_query', 'Search query is invalid.');
        self::assertSame('invalid_search_query',    $envelope['reason']);
        self::assertSame('Search query is invalid.', $envelope['message']);
    }

    #[Test]
    public function error_does_not_echo_arbitrary_input_beyond_what_caller_provides(): void
    {
        // Whatever the handler hands in, the helper returns. The
        // handler is the gatekeeper for what's safe to echo — the
        // helper never adds anything.
        $envelope = UiGridDataResponse::error('some_reason', 'some message');
        self::assertCount(3, $envelope);
        self::assertSame('some_reason', $envelope['reason']);
        self::assertSame('some message', $envelope['message']);
    }

    // -------------------------------------------------------------
    // DTO pins
    // -------------------------------------------------------------

    #[Test]
    public function pagination_dto_is_readonly_and_carries_only_documented_fields(): void
    {
        $pagination = new UiGridPaginationData(25, true, 'CURSORX');
        $props = array_keys(get_object_vars($pagination));
        self::assertSame(['limit', 'hasMore', 'nextCursor'], $props);
        $reflection = new \ReflectionClass($pagination);
        self::assertTrue($reflection->isReadOnly());
    }

    #[Test]
    public function filter_state_dto_is_readonly_and_carries_only_documented_fields(): void
    {
        $filters = new UiGridFilterState('hello', null);
        $props = array_keys(get_object_vars($filters));
        self::assertSame(['q', 'action'], $props);
        $reflection = new \ReflectionClass($filters);
        self::assertTrue($reflection->isReadOnly());
    }

    #[Test]
    public function pagination_dto_to_array_preserves_wire_key_order(): void
    {
        $payload = (new UiGridPaginationData(10, false, null))->toArray();
        self::assertSame(['limit', 'hasMore', 'nextCursor'], array_keys($payload));
    }

    #[Test]
    public function filter_state_dto_to_array_preserves_wire_key_order(): void
    {
        $payload = (new UiGridFilterState(null, null))->toArray();
        self::assertSame(['q', 'action'], array_keys($payload));
    }

    #[Test]
    public function null_next_cursor_serialises_as_null_not_missing_key(): void
    {
        $envelope = UiGridDataResponse::success(
            'g', [],
            new UiGridPaginationData(25, false, null),
            new UiGridFilterState(null, null),
        );
        // The pagination object MUST always have the nextCursor key
        // — otherwise the runtime's `pagination.nextCursor` access
        // would return undefined and the Next-link toggle would
        // misbehave.
        self::assertArrayHasKey('nextCursor', $envelope['pagination']);
        self::assertNull($envelope['pagination']['nextCursor']);
    }

    #[Test]
    public function null_filters_serialise_as_null_not_missing_keys(): void
    {
        $envelope = UiGridDataResponse::success(
            'g', [],
            new UiGridPaginationData(25, false, null),
            new UiGridFilterState(null, null),
        );
        self::assertArrayHasKey('q',      $envelope['filters']);
        self::assertArrayHasKey('action', $envelope['filters']);
        self::assertNull($envelope['filters']['q']);
        self::assertNull($envelope['filters']['action']);
    }
}
