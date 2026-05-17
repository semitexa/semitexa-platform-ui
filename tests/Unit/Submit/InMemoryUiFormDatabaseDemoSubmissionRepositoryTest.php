<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Submit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Submit\InMemoryUiFormDatabaseDemoSubmissionRepository;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormDatabaseDemoSubmissionRepositoryInterface;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormDemoSubmissionCursor;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormDemoSubmissionListCriteria;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormDemoSubmissionPage;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormDemoSubmissionRecord;

/**
 * Repository read-only listing contract — exercised against the
 * in-memory impl so the unit test does not need a database. The
 * ORM-backed sibling shares the same documented semantics (same
 * `recent()` shape, same clamping).
 *
 * Pins:
 *   - `recent()` returns newest-first;
 *   - `recent()` clamps to [1, MAX_RECENT_LIMIT];
 *   - empty repository returns an empty list;
 *   - `recent()` returns the canonical `UiFormDemoSubmissionRecord`
 *     readonly shape — no internal cache keys / class FQCNs leak.
 */
final class InMemoryUiFormDatabaseDemoSubmissionRepositoryTest extends TestCase
{
    private function record(string $id, int $submittedAt, array $values = ['contact_name' => 'Ada']): UiFormDemoSubmissionRecord
    {
        return new UiFormDemoSubmissionRecord(
            id: $id,
            formInstanceId: 'uci_db_recent_unit',
            actionName: 'platform.demo.storeContactDb',
            submittedAt: $submittedAt,
            values: $values,
        );
    }

    #[Test]
    public function recent_on_empty_repo_returns_empty_list(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        self::assertSame([], $repo->recent());
    }

    #[Test]
    public function recent_returns_newest_first(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->record('uifs_a', 1000));
        $repo->save($this->record('uifs_b', 3000));
        $repo->save($this->record('uifs_c', 2000));

        $rows = $repo->recent();
        self::assertSame(['uifs_b', 'uifs_c', 'uifs_a'], array_map(static fn ($r) => $r->id, $rows));
    }

    #[Test]
    public function recent_clamps_limit_to_max(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        for ($i = 1; $i <= 120; $i++) {
            // Canonical id shape — recent() now delegates to paginate(),
            // which may construct a next-cursor from the last returned
            // record. Cursor ids MUST match `uifs_[a-f0-9]{16}`.
            $repo->save($this->record('uifs_' . str_pad(dechex($i), 16, '0', STR_PAD_LEFT), $i));
        }
        $rows = $repo->recent(500); // ask for more than the max
        self::assertCount(UiFormDatabaseDemoSubmissionRepositoryInterface::MAX_RECENT_LIMIT, $rows);
    }

    #[Test]
    public function recent_clamps_zero_and_negative_limits_up_to_one(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->record('uifs_only', 1));
        self::assertCount(1, $repo->recent(0));
        self::assertCount(1, $repo->recent(-10));
    }

    #[Test]
    public function recent_honours_explicit_in_range_limit(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        for ($i = 1; $i <= 10; $i++) {
            // Canonical id shape — see recent_clamps_limit_to_max() note.
            $repo->save($this->record('uifs_' . str_pad(dechex($i), 16, '0', STR_PAD_LEFT), $i));
        }
        self::assertCount(3, $repo->recent(3));
    }

    #[Test]
    public function returned_records_carry_only_documented_fields(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->record('uifs_pin', 1));
        $rows = $repo->recent(1);
        $props = array_keys(get_object_vars($rows[0]));
        // The repository never invents extra fields — only the
        // documented readonly record shape.
        self::assertSame(
            ['id', 'formInstanceId', 'actionName', 'submittedAt', 'values'],
            $props,
        );
    }

    // ---------------------------------------------------------------
    // paginate() — keyset pagination contract
    // ---------------------------------------------------------------

    /**
     * Build an id matching the cursor's `uifs_[a-f0-9]{16}` shape. The
     * paginate path constructs cursors from record ids, so unit tests
     * MUST use canonical ids — anything else would fail in the cursor
     * constructor rather than in the repository being tested.
     */
    private static function canonicalId(int $seq): string
    {
        return 'uifs_' . str_pad(dechex($seq), 16, '0', STR_PAD_LEFT);
    }

    private function canonical(int $seq, int $submittedAt): UiFormDemoSubmissionRecord
    {
        return $this->record(self::canonicalId($seq), $submittedAt);
    }

    #[Test]
    public function paginate_on_empty_repo_returns_empty_page(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $page = $repo->paginate();
        self::assertInstanceOf(UiFormDemoSubmissionPage::class, $page);
        self::assertSame([], $page->records);
        self::assertFalse($page->hasMore);
        self::assertNull($page->nextCursor);
    }

    #[Test]
    public function paginate_first_page_is_newest_first(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->canonical(1, 1000));
        $repo->save($this->canonical(2, 3000));
        $repo->save($this->canonical(3, 2000));

        $page = $repo->paginate(null, 10);
        self::assertSame(
            [self::canonicalId(2), self::canonicalId(3), self::canonicalId(1)],
            array_map(static fn ($r) => $r->id, $page->records),
        );
        self::assertFalse($page->hasMore);
        self::assertNull($page->nextCursor);
    }

    #[Test]
    public function paginate_clamps_limit_to_max(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $max = UiFormDatabaseDemoSubmissionRepositoryInterface::MAX_RECENT_LIMIT;
        for ($i = 1; $i <= $max + 20; $i++) {
            $repo->save($this->canonical($i, $i));
        }
        $page = $repo->paginate(null, 500);
        self::assertSame($max, $page->limit);
        self::assertCount($max, $page->records);
    }

    #[Test]
    public function paginate_clamps_zero_and_negative_limits_up_to_one(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->canonical(1, 100));
        $repo->save($this->canonical(2, 200));

        $zero = $repo->paginate(null, 0);
        self::assertSame(1, $zero->limit);
        self::assertCount(1, $zero->records);

        $neg = $repo->paginate(null, -10);
        self::assertSame(1, $neg->limit);
        self::assertCount(1, $neg->records);
    }

    #[Test]
    public function paginate_detects_has_more_via_limit_plus_one(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        for ($i = 1; $i <= 5; $i++) {
            $repo->save($this->canonical($i, $i));
        }
        // Three rows requested out of five total -> there is more.
        $page = $repo->paginate(null, 3);
        self::assertCount(3, $page->records);
        self::assertTrue($page->hasMore);
        self::assertNotNull($page->nextCursor);
        // The next cursor must point at the LAST returned row.
        $last = $page->records[2];
        self::assertSame($last->submittedAt, $page->nextCursor->submittedAt);
        self::assertSame($last->id,          $page->nextCursor->id);
    }

    #[Test]
    public function paginate_returns_null_next_cursor_when_exactly_at_total(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        for ($i = 1; $i <= 3; $i++) {
            $repo->save($this->canonical($i, $i));
        }
        $page = $repo->paginate(null, 3);
        self::assertCount(3, $page->records);
        self::assertFalse($page->hasMore);
        self::assertNull($page->nextCursor);
    }

    #[Test]
    public function paginate_second_page_starts_strictly_after_cursor(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        for ($i = 1; $i <= 5; $i++) {
            $repo->save($this->canonical($i, $i));
        }
        $first = $repo->paginate(null, 2);
        self::assertCount(2, $first->records);
        self::assertTrue($first->hasMore);
        self::assertNotNull($first->nextCursor);

        $second = $repo->paginate($first->nextCursor, 2);
        // First page = ids 5, 4 (newest first by submittedAt).
        // Second page must start with id 3.
        self::assertSame(
            [self::canonicalId(3), self::canonicalId(2)],
            array_map(static fn ($r) => $r->id, $second->records),
        );
        // Still one more (id 1).
        self::assertTrue($second->hasMore);

        $third = $repo->paginate($second->nextCursor, 2);
        self::assertSame([self::canonicalId(1)], array_map(static fn ($r) => $r->id, $third->records));
        self::assertFalse($third->hasMore);
        self::assertNull($third->nextCursor);
    }

    #[Test]
    public function paginate_does_not_repeat_or_skip_records_across_pages(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        for ($i = 1; $i <= 7; $i++) {
            $repo->save($this->canonical($i, $i));
        }
        $seen = [];
        $cursor = null;
        $guard = 0;
        do {
            $page = $repo->paginate($cursor, 3);
            foreach ($page->records as $r) {
                $seen[] = $r->id;
            }
            $cursor = $page->nextCursor;
            if (++$guard > 10) {
                self::fail('paginate did not terminate');
            }
        } while ($page->hasMore);

        self::assertCount(7, $seen, 'every record must appear exactly once');
        self::assertSame(count($seen), count(array_unique($seen)), 'no duplicates across pages');
    }

    #[Test]
    public function paginate_breaks_ties_on_identical_submitted_at_with_id_desc(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        // All four rows share the same submittedAt — the tie-breaker
        // is `id` DESC. Without the tie-breaker, pagination across
        // these rows could skip or repeat.
        $repo->save($this->canonical(1, 5000));
        $repo->save($this->canonical(2, 5000));
        $repo->save($this->canonical(3, 5000));
        $repo->save($this->canonical(4, 5000));

        $first = $repo->paginate(null, 2);
        self::assertSame(
            [self::canonicalId(4), self::canonicalId(3)],
            array_map(static fn ($r) => $r->id, $first->records),
        );
        self::assertTrue($first->hasMore);

        $second = $repo->paginate($first->nextCursor, 2);
        self::assertSame(
            [self::canonicalId(2), self::canonicalId(1)],
            array_map(static fn ($r) => $r->id, $second->records),
        );
        self::assertFalse($second->hasMore);
    }

    #[Test]
    public function paginate_records_match_recent_for_first_page(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        for ($i = 1; $i <= 5; $i++) {
            $repo->save($this->canonical($i, $i * 100));
        }
        $recent = $repo->recent(3);
        $page   = $repo->paginate(null, 3);
        self::assertSame(
            array_map(static fn ($r) => $r->id, $recent),
            array_map(static fn ($r) => $r->id, $page->records),
            'recent($n) and paginate(null,$n)->records must match',
        );
    }

    #[Test]
    public function paginate_page_object_carries_only_documented_fields(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->canonical(1, 1));
        $page = $repo->paginate();
        $props = array_keys(get_object_vars($page));
        self::assertSame(['records', 'nextCursor', 'limit', 'hasMore'], $props);
    }

    // ---------------------------------------------------------------
    // searchPage() — bounded diagnostic search
    // ---------------------------------------------------------------

    private function recordWithFields(
        int $seq,
        int $submittedAt,
        string $contactName = 'Ada',
        string $contactTopic = '',
        string $contactMessage = '',
        string $actionName = 'platform.demo.storeContactDb',
    ): UiFormDemoSubmissionRecord {
        return new UiFormDemoSubmissionRecord(
            id:             self::canonicalId($seq),
            formInstanceId: 'uci_search_unit',
            actionName:     $actionName,
            submittedAt:    $submittedAt,
            values:         [
                'contact_name'    => $contactName,
                'contact_topic'   => $contactTopic,
                'contact_message' => $contactMessage,
            ],
        );
    }

    #[Test]
    public function search_with_unfiltered_criteria_matches_paginate(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        for ($i = 1; $i <= 5; $i++) {
            $repo->save($this->recordWithFields($i, $i * 100));
        }
        $criteria = UiFormDemoSubmissionListCriteria::fromRequest(null, null, '3');
        $search   = $repo->searchPage($criteria, null);
        $paginate = $repo->paginate(null, 3);
        self::assertSame(
            array_map(static fn ($r) => $r->id, $paginate->records),
            array_map(static fn ($r) => $r->id, $search->records),
        );
        self::assertSame($paginate->hasMore, $search->hasMore);
    }

    #[Test]
    public function search_matches_contact_name(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->recordWithFields(1, 100, contactName: 'Ada Lovelace'));
        $repo->save($this->recordWithFields(2, 200, contactName: 'Bea Beatty'));
        $repo->save($this->recordWithFields(3, 300, contactName: 'Cyn Strong'));
        $criteria = UiFormDemoSubmissionListCriteria::fromRequest('lovelace', null, null);
        $page = $repo->searchPage($criteria, null);
        self::assertSame([self::canonicalId(1)], array_map(static fn ($r) => $r->id, $page->records));
    }

    #[Test]
    public function search_matches_contact_topic(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->recordWithFields(1, 100, contactTopic: 'feature request'));
        $repo->save($this->recordWithFields(2, 200, contactTopic: 'bug report'));
        $criteria = UiFormDemoSubmissionListCriteria::fromRequest('feature', null, null);
        $page = $repo->searchPage($criteria, null);
        self::assertSame([self::canonicalId(1)], array_map(static fn ($r) => $r->id, $page->records));
    }

    #[Test]
    public function search_matches_contact_message(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->recordWithFields(1, 100, contactMessage: 'I love this product'));
        $repo->save($this->recordWithFields(2, 200, contactMessage: 'Something broke'));
        $criteria = UiFormDemoSubmissionListCriteria::fromRequest('love', null, null);
        $page = $repo->searchPage($criteria, null);
        self::assertSame([self::canonicalId(1)], array_map(static fn ($r) => $r->id, $page->records));
    }

    #[Test]
    public function search_is_case_insensitive(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->recordWithFields(1, 100, contactName: 'Ada LOVELACE'));
        $criteria = UiFormDemoSubmissionListCriteria::fromRequest('lovelace', null, null);
        $page = $repo->searchPage($criteria, null);
        self::assertCount(1, $page->records);
    }

    #[Test]
    public function search_is_newest_first(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->recordWithFields(1, 100, contactName: 'common'));
        $repo->save($this->recordWithFields(2, 200, contactName: 'common'));
        $repo->save($this->recordWithFields(3, 300, contactName: 'common'));
        $criteria = UiFormDemoSubmissionListCriteria::fromRequest('common', null, null);
        $page = $repo->searchPage($criteria, null);
        self::assertSame(
            [self::canonicalId(3), self::canonicalId(2), self::canonicalId(1)],
            array_map(static fn ($r) => $r->id, $page->records),
        );
    }

    #[Test]
    public function search_paginates_without_duplicates(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        for ($i = 1; $i <= 7; $i++) {
            $repo->save($this->recordWithFields($i, $i * 100, contactMessage: 'match-me'));
        }
        // Add some non-matching rows.
        for ($i = 100; $i < 103; $i++) {
            $repo->save($this->recordWithFields($i, $i * 100, contactMessage: 'no'));
        }
        $criteria = UiFormDemoSubmissionListCriteria::fromRequest('match-me', null, '3');
        $cursor = null;
        $seen = [];
        $guard = 0;
        do {
            $page = $repo->searchPage($criteria, $cursor);
            foreach ($page->records as $r) {
                $seen[] = $r->id;
            }
            $cursor = $page->nextCursor;
            if (++$guard > 10) {
                self::fail('searchPage did not terminate');
            }
        } while ($page->hasMore);

        self::assertCount(7, $seen);
        self::assertSame(count($seen), count(array_unique($seen)));
    }

    #[Test]
    public function action_filter_returns_only_matching_action(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->recordWithFields(1, 100, contactName: 'Ada', actionName: 'platform.demo.storeContactDb'));
        $repo->save($this->recordWithFields(2, 200, contactName: 'Bea', actionName: 'platform.demo.storeContact'));
        $criteria = UiFormDemoSubmissionListCriteria::fromRequest(null, 'platform.demo.storeContactDb', null);
        $page = $repo->searchPage($criteria, null);
        self::assertSame([self::canonicalId(1)], array_map(static fn ($r) => $r->id, $page->records));
    }

    #[Test]
    public function q_and_action_compose(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->recordWithFields(1, 100, contactName: 'lovelace', actionName: 'platform.demo.storeContactDb'));
        $repo->save($this->recordWithFields(2, 200, contactName: 'lovelace', actionName: 'platform.demo.storeContact'));
        $repo->save($this->recordWithFields(3, 300, contactName: 'turing',   actionName: 'platform.demo.storeContactDb'));
        $criteria = UiFormDemoSubmissionListCriteria::fromRequest('lovelace', 'platform.demo.storeContactDb', null);
        $page = $repo->searchPage($criteria, null);
        self::assertSame([self::canonicalId(1)], array_map(static fn ($r) => $r->id, $page->records));
    }

    #[Test]
    public function search_treats_percent_as_literal_not_wildcard(): void
    {
        // Diagnostic-grade search MUST treat `%` as a literal — the
        // repository's substring match (in-memory) doesn't care about
        // LIKE semantics, but pin the contract here so the ORM impl
        // is held to the same bar.
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->recordWithFields(1, 100, contactMessage: '100% match'));
        $repo->save($this->recordWithFields(2, 200, contactMessage: 'no'));
        $criteria = UiFormDemoSubmissionListCriteria::fromRequest('100%', null, null);
        $page = $repo->searchPage($criteria, null);
        self::assertSame([self::canonicalId(1)], array_map(static fn ($r) => $r->id, $page->records));
    }

    #[Test]
    public function search_treats_underscore_as_literal_not_wildcard(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->recordWithFields(1, 100, contactName: 'snake_case_name'));
        $repo->save($this->recordWithFields(2, 200, contactName: 'snakeXcaseXname'));
        $criteria = UiFormDemoSubmissionListCriteria::fromRequest('snake_case', null, null);
        $page = $repo->searchPage($criteria, null);
        // Underscore is a literal, so only the `snake_case_name` row matches.
        self::assertSame([self::canonicalId(1)], array_map(static fn ($r) => $r->id, $page->records));
    }

    #[Test]
    public function search_handles_quotes_safely(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->recordWithFields(1, 100, contactMessage: "O'Reilly"));
        $criteria = UiFormDemoSubmissionListCriteria::fromRequest("O'Reilly", null, null);
        $page = $repo->searchPage($criteria, null);
        self::assertCount(1, $page->records);
    }

    #[Test]
    public function search_with_sql_looking_input_is_harmless(): void
    {
        // SQL-looking inputs must not be interpreted — they are data.
        // The in-memory impl does a literal substring match; the ORM
        // impl binds the term as a parameter (never spliced into SQL).
        // Either way the input is harmless.
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->recordWithFields(1, 100, contactName: 'Ada'));
        $criteria = UiFormDemoSubmissionListCriteria::fromRequest("' OR 1=1 --", null, null);
        $page = $repo->searchPage($criteria, null);
        // No row contains the literal `' OR 1=1 --` substring.
        self::assertSame([], $page->records);
    }

    #[Test]
    public function search_returns_no_results_for_unmatched_query(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->recordWithFields(1, 100, contactName: 'Ada'));
        $criteria = UiFormDemoSubmissionListCriteria::fromRequest('nobody', null, null);
        $page = $repo->searchPage($criteria, null);
        self::assertSame([], $page->records);
        self::assertFalse($page->hasMore);
        self::assertNull($page->nextCursor);
    }

    #[Test]
    public function search_next_cursor_carries_filter_fingerprint(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        for ($i = 1; $i <= 5; $i++) {
            $repo->save($this->recordWithFields($i, $i * 100, contactName: 'match'));
        }
        $criteria = UiFormDemoSubmissionListCriteria::fromRequest('match', null, '2');
        $page = $repo->searchPage($criteria, null);
        self::assertTrue($page->hasMore);
        self::assertNotNull($page->nextCursor);
        self::assertSame($criteria->fingerprint(), $page->nextCursor->filterFingerprint);
    }

    // ----------------------------------------------------------------
    // Sort-slice — server-owned allow-listed sorting on searchPage().
    // ----------------------------------------------------------------

    #[Test]
    public function searchPage_with_default_sort_matches_paginate_order(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        for ($i = 1; $i <= 5; $i++) {
            $repo->save($this->recordWithFields($i, $i * 100));
        }
        $criteria = UiFormDemoSubmissionListCriteria::fromRequest(null, null, '10', 'submittedAt_desc');
        $search   = $repo->searchPage($criteria, null);
        $paginate = $repo->paginate(null, 10);
        self::assertSame(
            array_map(static fn ($r) => $r->id, $paginate->records),
            array_map(static fn ($r) => $r->id, $search->records),
        );
    }

    #[Test]
    public function searchPage_ascending_sort_returns_oldest_first(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->recordWithFields(1, 1000));
        $repo->save($this->recordWithFields(2, 3000));
        $repo->save($this->recordWithFields(3, 2000));

        $criteria = UiFormDemoSubmissionListCriteria::fromRequest(null, null, '10', 'submittedAt_asc');
        $page = $repo->searchPage($criteria, null);
        self::assertSame(
            [self::canonicalId(1), self::canonicalId(3), self::canonicalId(2)],
            array_map(static fn ($r) => $r->id, $page->records),
        );
    }

    #[Test]
    public function searchPage_ascending_breaks_ties_with_id_asc(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->recordWithFields(1, 5000));
        $repo->save($this->recordWithFields(2, 5000));
        $repo->save($this->recordWithFields(3, 5000));
        $repo->save($this->recordWithFields(4, 5000));

        $criteria = UiFormDemoSubmissionListCriteria::fromRequest(null, null, '10', 'submittedAt_asc');
        $page = $repo->searchPage($criteria, null);
        self::assertSame(
            [self::canonicalId(1), self::canonicalId(2), self::canonicalId(3), self::canonicalId(4)],
            array_map(static fn ($r) => $r->id, $page->records),
        );
    }

    #[Test]
    public function searchPage_ascending_paginates_without_duplicates_or_gaps(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        for ($i = 1; $i <= 7; $i++) {
            $repo->save($this->recordWithFields($i, $i * 100));
        }
        $criteria = UiFormDemoSubmissionListCriteria::fromRequest(null, null, '3', 'submittedAt_asc');
        $cursor = null;
        $seen = [];
        $guard = 0;
        do {
            $page = $repo->searchPage($criteria, $cursor);
            foreach ($page->records as $r) {
                $seen[] = $r->id;
            }
            $cursor = $page->nextCursor;
            if (++$guard > 10) {
                self::fail('searchPage(asc) did not terminate');
            }
        } while ($page->hasMore);

        self::assertSame(
            [
                self::canonicalId(1), self::canonicalId(2), self::canonicalId(3),
                self::canonicalId(4), self::canonicalId(5), self::canonicalId(6),
                self::canonicalId(7),
            ],
            $seen,
        );
    }

    #[Test]
    public function searchPage_descending_paginates_without_duplicates_under_explicit_sort(): void
    {
        // Same loop as the ASC case but with the explicit
        // `submittedAt_desc` token (NOT the default = null path).
        // The repo must still produce zero duplicates and zero gaps.
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        for ($i = 1; $i <= 7; $i++) {
            $repo->save($this->recordWithFields($i, $i * 100));
        }
        $criteria = UiFormDemoSubmissionListCriteria::fromRequest(null, null, '3', 'submittedAt_desc');
        $cursor = null;
        $seen = [];
        $guard = 0;
        do {
            $page = $repo->searchPage($criteria, $cursor);
            foreach ($page->records as $r) {
                $seen[] = $r->id;
            }
            $cursor = $page->nextCursor;
            if (++$guard > 10) {
                self::fail('searchPage(desc) did not terminate');
            }
        } while ($page->hasMore);

        self::assertSame(
            [
                self::canonicalId(7), self::canonicalId(6), self::canonicalId(5),
                self::canonicalId(4), self::canonicalId(3), self::canonicalId(2),
                self::canonicalId(1),
            ],
            $seen,
        );
    }

    #[Test]
    public function searchPage_ascending_composes_with_query_and_action(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        $repo->save($this->recordWithFields(1, 300, contactName: 'match', actionName: 'platform.demo.storeContactDb'));
        $repo->save($this->recordWithFields(2, 100, contactName: 'match', actionName: 'platform.demo.storeContactDb'));
        $repo->save($this->recordWithFields(3, 200, contactName: 'match', actionName: 'platform.demo.storeContactDb'));
        $repo->save($this->recordWithFields(4, 50,  contactName: 'no',    actionName: 'platform.demo.storeContactDb'));
        $repo->save($this->recordWithFields(5, 250, contactName: 'match', actionName: 'platform.demo.other'));

        $criteria = UiFormDemoSubmissionListCriteria::fromRequest(
            'match',
            'platform.demo.storeContactDb',
            '10',
            'submittedAt_asc',
        );
        $page = $repo->searchPage($criteria, null);
        self::assertSame(
            [self::canonicalId(2), self::canonicalId(3), self::canonicalId(1)],
            array_map(static fn ($r) => $r->id, $page->records),
        );
    }

    #[Test]
    public function searchPage_next_cursor_carries_sort_aware_fingerprint(): void
    {
        $repo = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        for ($i = 1; $i <= 5; $i++) {
            $repo->save($this->recordWithFields($i, $i * 100));
        }
        $asc  = UiFormDemoSubmissionListCriteria::fromRequest(null, null, '2', 'submittedAt_asc');
        $desc = UiFormDemoSubmissionListCriteria::fromRequest(null, null, '2', 'submittedAt_desc');

        $page = $repo->searchPage($asc, null);
        self::assertTrue($page->hasMore);
        self::assertNotNull($page->nextCursor);
        self::assertSame($asc->fingerprint(), $page->nextCursor->filterFingerprint);
        // The DESC criteria's fingerprint is null (default state),
        // so an ASC-minted cursor's fingerprint cannot match a DESC
        // criteria — pin the divergence.
        self::assertNotSame($desc->fingerprint(), $page->nextCursor->filterFingerprint);
    }
}
