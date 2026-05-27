<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Grid;

/**
 * Marker for any object that can serialise itself into the
 * `pagination` slot of the {@see UiGridDataResponse} envelope.
 *
 * Two shapes implement it:
 *   - {@see UiGridPaginationData} — the legacy cursor-only wire shape
 *     (`limit` / `hasMore` / `nextCursor`).
 *   - {@see \Semitexa\PlatformUi\Domain\Model\Pagination\PaginationMetadata}
 *     — the windowed superset that also carries `mode` / `currentPage`
 *     / `totalCount` / `windowSize`.
 *
 * `UiGridDataResponse::success()` accepts the interface so a grid-data
 * endpoint can emit either footer shape without the envelope contract
 * caring which.
 */
interface UiGridPaginationPayload
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
