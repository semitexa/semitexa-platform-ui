<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Event;

/**
 * One page of the read-only diagnostic listing.
 *
 *   - `records`    : the records on this page, newest-first.
 *   - `limit`      : the clamped effective limit the repository
 *                    used. `records` contains at most this many
 *                    entries.
 *   - `hasMore`    : true iff at least one row exists beyond this
 *                    page. Detected by the repository fetching
 *                    `limit + 1` rows and trimming.
 *   - `nextCursor` : keyset cursor pointing at the LAST returned
 *                    record. `null` when `hasMore` is false (or
 *                    when the page is empty). Callers encode this
 *                    to a wire string via
 *                    {@see UiFormDemoSubmissionCursor::encode()}.
 *
 * Read-only by construction (`final readonly`); the repository
 * returns one Page per call, no mutation paths exist on the type.
 */
final readonly class UiFormDemoSubmissionPage
{
    /**
     * @param list<UiFormDemoSubmissionRecord> $records
     */
    public function __construct(
        public array $records,
        public ?UiFormDemoSubmissionCursor $nextCursor,
        public int $limit,
        public bool $hasMore,
    ) {}
}
