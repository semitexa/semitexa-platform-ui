<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Domain\Pagination;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Domain\Model\Pagination\PaginationMetadata;

final class PaginationMetadataTest extends TestCase
{
    #[Test]
    public function to_array_keeps_legacy_cursor_keys_first(): void
    {
        $meta = new PaginationMetadata(
            mode: PaginationMetadata::MODE_CURSOR,
            currentPage: 1,
            limit: 25,
            nextCursor: 'abc',
            hasMore: true,
        );

        $arr = $meta->toArray();

        // Legacy UiGridPaginationData keys must still be present and
        // carry the same meaning so existing consumers don't break.
        self::assertSame(25, $arr['limit']);
        self::assertTrue($arr['hasMore']);
        self::assertSame('abc', $arr['nextCursor']);
        // Superset keys.
        self::assertSame('cursor', $arr['mode']);
        self::assertSame(1, $arr['currentPage']);
        self::assertNull($arr['totalCount']);
        self::assertSame(5, $arr['windowSize']);
    }

    #[Test]
    public function cursor_factory_carries_no_total(): void
    {
        $meta = PaginationMetadata::cursor(
            limit: 10,
            hasMore: false,
            nextCursor: null,
            currentPage: 3,
            hasPrev: true,
            windowSize: 5,
        );

        self::assertSame(PaginationMetadata::MODE_CURSOR, $meta->mode);
        self::assertNull($meta->totalCount);
        self::assertSame(3, $meta->currentPage);
        self::assertTrue($meta->hasPrev);
        self::assertFalse($meta->hasMore);
        self::assertNull($meta->nextCursor);
    }

    #[Test]
    public function offset_factory_derives_has_more_has_prev_from_total(): void
    {
        // 95 rows / 25 per page = 4 pages. Page 2 has both neighbours.
        $meta = PaginationMetadata::offset(
            currentPage: 2,
            limit: 25,
            totalCount: 95,
        );

        self::assertSame(PaginationMetadata::MODE_COUNT, $meta->mode);
        self::assertSame(95, $meta->totalCount);
        self::assertTrue($meta->hasPrev);
        self::assertTrue($meta->hasMore);
        self::assertNull($meta->nextCursor);
    }

    #[Test]
    public function offset_factory_clears_has_more_on_last_page(): void
    {
        $meta = PaginationMetadata::offset(
            currentPage: 4,
            limit: 25,
            totalCount: 95,
        );

        self::assertFalse($meta->hasMore);
        self::assertTrue($meta->hasPrev);
    }

    #[Test]
    public function offset_factory_first_page_has_no_prev(): void
    {
        $meta = PaginationMetadata::offset(
            currentPage: 1,
            limit: 25,
            totalCount: 10,
        );

        self::assertFalse($meta->hasPrev);
        // 10 rows / 25 = 1 page → no next either.
        self::assertFalse($meta->hasMore);
    }

    #[Test]
    public function offset_factory_can_declare_offset_mode(): void
    {
        $meta = PaginationMetadata::offset(
            currentPage: 1,
            limit: 25,
            totalCount: 50,
            mode: PaginationMetadata::MODE_OFFSET,
        );

        self::assertSame(PaginationMetadata::MODE_OFFSET, $meta->mode);
    }
}
