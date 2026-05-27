<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Attribute;

use Attribute;

/**
 * Declares pagination defaults + strategy for a grid component.
 *
 * Placed on the class itself. `$defaultLimit` MUST appear in
 * `$limitOptions`; that cross-field rule is validated by
 * GridComponentMetadataProvider at boot (it needs the class name for a
 * useful error). The single-field invariants below are validated in
 * the constructor so a malformed attribute fails fast the moment the
 * metadata provider instantiates it.
 *
 *   - $defaultLimit  — initial page size.
 *   - $limitOptions  — selectable page sizes (non-empty list of
 *                      positive integers).
 *   - $mode          — pagination strategy the component DECLARES.
 *                      The DataProvider may further resolve `auto`
 *                      at runtime based on data volume:
 *                        * 'cursor' (default) — keyset/cursor
 *                          navigation; "hasMore" only; window grows
 *                          as visited pages accumulate. Backward-
 *                          compatible default.
 *                        * 'offset' — page numbers + limit; needs a
 *                          total count; offset reads.
 *                        * 'count'  — like offset but with an explicit
 *                          known total count.
 *                        * 'auto'   — DataProvider decides at runtime
 *                          (real count below threshold → count/offset;
 *                          otherwise → cursor).
 *   - $windowSize    — visible page-button count. Must be >= 3 and
 *                      ODD so the current page can sit centered.
 *   - $autoCountThreshold — in 'auto' mode, the DataProvider performs
 *                      an exact (bounded) count only while the total is
 *                      below this; above it, it degrades to cursor.
 *                      Must be >= 0.
 *
 * Backward compatibility: the two-argument form
 * `#[WithPagination(defaultLimit: 25, limitOptions: [10, 25, 50])]`
 * keeps `mode = 'cursor'` and the prior behaviour unchanged.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class WithPagination
{
    public const MODE_CURSOR = 'cursor';
    public const MODE_OFFSET = 'offset';
    public const MODE_COUNT  = 'count';
    public const MODE_AUTO   = 'auto';

    /** @var list<string> */
    public const VALID_MODES = [
        self::MODE_CURSOR,
        self::MODE_OFFSET,
        self::MODE_COUNT,
        self::MODE_AUTO,
    ];

    /**
     * @param list<int> $limitOptions
     */
    public function __construct(
        public readonly int $defaultLimit = 25,
        public readonly array $limitOptions = [10, 25, 50, 100],
        public readonly string $mode = self::MODE_CURSOR,
        public readonly int $windowSize = 5,
        public readonly int $autoCountThreshold = 1000,
    ) {
        if (!in_array($mode, self::VALID_MODES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'WithPagination: mode "%s" is invalid; expected one of [%s].',
                $mode,
                implode(', ', self::VALID_MODES),
            ));
        }
        if ($windowSize < 3) {
            throw new \InvalidArgumentException(sprintf(
                'WithPagination: windowSize must be >= 3, got %d.',
                $windowSize,
            ));
        }
        if ($windowSize % 2 === 0) {
            throw new \InvalidArgumentException(sprintf(
                'WithPagination: windowSize must be odd so the current page '
                . 'can be centered, got %d.',
                $windowSize,
            ));
        }
        if ($autoCountThreshold < 0) {
            throw new \InvalidArgumentException(sprintf(
                'WithPagination: autoCountThreshold must be >= 0, got %d.',
                $autoCountThreshold,
            ));
        }
    }
}
