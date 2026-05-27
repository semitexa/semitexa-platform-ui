<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Pagination;

use Semitexa\PlatformUi\Domain\Model\Grid\UiGridPaginationPayload;

/**
 * Resolved pagination state a DataProvider hands to the `platform.grid`
 * runtime so it can render the right footer for the strategy in play.
 *
 * This is the richer SUPERSET of the legacy
 * {@see \Semitexa\PlatformUi\Domain\Model\Grid\UiGridPaginationData}
 * wire shape: {@see toArray()} still emits the original
 * `limit` / `hasMore` / `nextCursor` keys, so cursor-mode consumers
 * (and the no-JS `/grid-data` REST fallback) keep working byte-for-byte.
 * The added keys (`mode`, `currentPage`, `totalCount`, …) let the
 * runtime switch between the relative cursor footer and a windowed
 * page-number footer without a second contract.
 *
 * `mode` is always a RESOLVED strategy — `cursor`, `offset`, or
 * `count`. The `auto` declared on `#[WithPagination]` is collapsed to
 * one of these by the DataProvider at runtime; it never reaches the
 * wire.
 *
 * Pure data carrier — no clamping, no derivation. The DataProvider
 * hands in already-clamped values; this object only pins the wire
 * shape so a key cannot be silently renamed.
 */
final readonly class PaginationMetadata implements UiGridPaginationPayload
{
    public const MODE_CURSOR = 'cursor';
    public const MODE_OFFSET = 'offset';
    public const MODE_COUNT  = 'count';

    public function __construct(
        public string $mode,
        public int $currentPage,
        public int $limit,
        public ?int $totalCount = null,
        public ?int $estimatedTotal = null,
        public ?string $nextCursor = null,
        public ?string $prevCursor = null,
        public bool $hasMore = false,
        public bool $hasPrev = false,
        public int $windowSize = 5,
    ) {}

    /**
     * Cursor-mode footer: keyset navigation with "hasMore" only.
     * `currentPage` carries the visited-page count so the runtime can
     * render the visited-pages window; no total is known.
     */
    public static function cursor(
        int $limit,
        bool $hasMore,
        ?string $nextCursor,
        int $currentPage = 1,
        bool $hasPrev = false,
        int $windowSize = 5,
        ?int $estimatedTotal = null,
    ): self {
        return new self(
            mode: self::MODE_CURSOR,
            currentPage: $currentPage,
            limit: $limit,
            totalCount: null,
            estimatedTotal: $estimatedTotal,
            nextCursor: $nextCursor,
            prevCursor: null,
            hasMore: $hasMore,
            hasPrev: $hasPrev,
            windowSize: $windowSize,
        );
    }

    /**
     * Offset/count-mode footer: a known total enables a full windowed
     * page-number strip and arbitrary page-N jumps. `$mode` is
     * `count` when the total is exact (the common case) or `offset`
     * when the component explicitly declared offset navigation.
     */
    public static function offset(
        int $currentPage,
        int $limit,
        int $totalCount,
        int $windowSize = 5,
        string $mode = self::MODE_COUNT,
    ): self {
        $totalPages = $limit > 0 ? (int) ceil($totalCount / $limit) : 1;
        $totalPages = max(1, $totalPages);

        return new self(
            mode: $mode,
            currentPage: $currentPage,
            limit: $limit,
            totalCount: $totalCount,
            estimatedTotal: null,
            nextCursor: null,
            prevCursor: null,
            hasMore: $currentPage < $totalPages,
            hasPrev: $currentPage > 1,
            windowSize: $windowSize,
        );
    }

    /**
     * @return array{
     *     mode:string, currentPage:int, limit:int, totalCount:?int,
     *     estimatedTotal:?int, nextCursor:?string, prevCursor:?string,
     *     hasMore:bool, hasPrev:bool, windowSize:int
     * }
     */
    public function toArray(): array
    {
        return [
            // Legacy cursor wire keys — kept first and unchanged so
            // existing consumers and the /grid-data REST fallback read
            // identically.
            'limit'          => $this->limit,
            'hasMore'        => $this->hasMore,
            'nextCursor'     => $this->nextCursor,
            // Windowed-footer additions.
            'mode'           => $this->mode,
            'currentPage'    => $this->currentPage,
            'totalCount'     => $this->totalCount,
            'estimatedTotal' => $this->estimatedTotal,
            'prevCursor'     => $this->prevCursor,
            'hasPrev'        => $this->hasPrev,
            'windowSize'     => $this->windowSize,
        ];
    }
}
