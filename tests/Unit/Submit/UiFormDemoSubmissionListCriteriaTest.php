<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Submit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormDatabaseDemoSubmissionRepositoryInterface;
use Semitexa\PlatformUi\Domain\Exception\UiFormDemoSubmissionSearchException;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormDemoSubmissionListCriteria;

/**
 * Criteria normalisation + fingerprint contract.
 *
 * Pins:
 *   - q is trimmed + empty→null;
 *   - oversized q rejected with safe reason code;
 *   - action restricted to allow-list;
 *   - rejected action does NOT echo the bad value;
 *   - limit clamped to [1, MAX_RECENT_LIMIT] with default fallback;
 *   - fingerprint is null for empty criteria, stable + non-secret
 *     16-hex for non-empty criteria;
 *   - identical canonical input produces identical fingerprint
 *     regardless of case differences in q.
 */
final class UiFormDemoSubmissionListCriteriaTest extends TestCase
{
    #[Test]
    public function trims_query_whitespace(): void
    {
        $c = UiFormDemoSubmissionListCriteria::fromRequest('  hello  ', null, null);
        self::assertSame('hello', $c->query);
    }

    #[Test]
    public function empty_or_whitespace_query_becomes_null(): void
    {
        foreach (['', '   ', "\n\t  "] as $raw) {
            $c = UiFormDemoSubmissionListCriteria::fromRequest($raw, null, null);
            self::assertNull($c->query, "raw '{$raw}' must normalise to null");
        }
        $c = UiFormDemoSubmissionListCriteria::fromRequest(null, null, null);
        self::assertNull($c->query);
    }

    #[Test]
    public function rejects_oversized_query_with_invalid_search_query(): void
    {
        $tooLong = str_repeat('a', UiFormDemoSubmissionListCriteria::MAX_QUERY_LENGTH + 1);
        try {
            UiFormDemoSubmissionListCriteria::fromRequest($tooLong, null, null);
            self::fail('Expected invalid_search_query exception');
        } catch (UiFormDemoSubmissionSearchException $e) {
            self::assertSame('invalid_search_query', $e->reasonCode);
            self::assertSame('Search query is invalid.', $e->getMessage());
            // Safety guarantee: the bad input is never quoted back.
            self::assertStringNotContainsString($tooLong, $e->getMessage());
        }
    }

    #[Test]
    public function accepts_query_at_exact_max_length(): void
    {
        $atMax = str_repeat('a', UiFormDemoSubmissionListCriteria::MAX_QUERY_LENGTH);
        $c = UiFormDemoSubmissionListCriteria::fromRequest($atMax, null, null);
        self::assertSame($atMax, $c->query);
    }

    #[Test]
    public function accepts_allow_listed_action(): void
    {
        $c = UiFormDemoSubmissionListCriteria::fromRequest(null, 'platform.demo.storeContactDb', null);
        self::assertSame('platform.demo.storeContactDb', $c->actionName);
    }

    #[Test]
    public function rejects_unknown_action_with_invalid_action_filter(): void
    {
        try {
            UiFormDemoSubmissionListCriteria::fromRequest(null, 'platform.evil.dropTable', null);
            self::fail('Expected invalid_action_filter exception');
        } catch (UiFormDemoSubmissionSearchException $e) {
            self::assertSame('invalid_action_filter', $e->reasonCode);
            self::assertSame('Action filter is invalid.', $e->getMessage());
            // Bad action name must NOT leak through the message.
            self::assertStringNotContainsString('platform.evil.dropTable', $e->getMessage());
        }
    }

    #[Test]
    public function empty_action_becomes_null(): void
    {
        foreach (['', '   ', null] as $raw) {
            $c = UiFormDemoSubmissionListCriteria::fromRequest(null, $raw, null);
            self::assertNull($c->actionName);
        }
    }

    #[Test]
    public function clamps_limit_to_max(): void
    {
        $c = UiFormDemoSubmissionListCriteria::fromRequest(null, null, '999999');
        self::assertSame(UiFormDatabaseDemoSubmissionRepositoryInterface::MAX_RECENT_LIMIT, $c->limit);
    }

    #[Test]
    public function falls_back_for_non_numeric_or_empty_limit(): void
    {
        foreach ([null, '', '   ', 'banana', '1; DROP', '-5'] as $raw) {
            $c = UiFormDemoSubmissionListCriteria::fromRequest(null, null, $raw);
            self::assertSame(
                UiFormDatabaseDemoSubmissionRepositoryInterface::DEFAULT_RECENT_LIMIT,
                $c->limit,
                "raw limit " . var_export($raw, true) . " must fall back to default",
            );
        }
    }

    #[Test]
    public function clamps_zero_limit_to_one(): void
    {
        // Zero is digits-only, so it passes the digit regex but the
        // [1, MAX] clamp pulls it up to 1. This is intentional — a
        // GET ?limit=0 should not be silently swapped with the
        // default; it should give the smallest meaningful page.
        $c = UiFormDemoSubmissionListCriteria::fromRequest(null, null, '0');
        self::assertSame(1, $c->limit);
    }

    #[Test]
    public function unfiltered_criteria_returns_null_fingerprint(): void
    {
        $c = UiFormDemoSubmissionListCriteria::fromRequest(null, null, '25');
        self::assertTrue($c->isUnfiltered());
        self::assertNull($c->fingerprint());
    }

    #[Test]
    public function filtered_criteria_produces_stable_16hex_fingerprint(): void
    {
        $c = UiFormDemoSubmissionListCriteria::fromRequest('hello', null, '25');
        self::assertFalse($c->isUnfiltered());
        $fp = $c->fingerprint();
        self::assertNotNull($fp);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{16}\z/', $fp);
    }

    #[Test]
    public function fingerprint_is_case_insensitive_on_query(): void
    {
        $low = UiFormDemoSubmissionListCriteria::fromRequest('hello', null, null);
        $up  = UiFormDemoSubmissionListCriteria::fromRequest('HELLO', null, null);
        self::assertSame($low->fingerprint(), $up->fingerprint());
    }

    #[Test]
    public function fingerprint_differs_for_different_query(): void
    {
        $a = UiFormDemoSubmissionListCriteria::fromRequest('alpha', null, null);
        $b = UiFormDemoSubmissionListCriteria::fromRequest('bravo', null, null);
        self::assertNotSame($a->fingerprint(), $b->fingerprint());
    }

    #[Test]
    public function fingerprint_differs_when_action_added(): void
    {
        $q   = UiFormDemoSubmissionListCriteria::fromRequest('hello', null, null);
        $qa  = UiFormDemoSubmissionListCriteria::fromRequest('hello', 'platform.demo.storeContactDb', null);
        self::assertNotSame($q->fingerprint(), $qa->fingerprint());
    }

    #[Test]
    public function fingerprint_for_action_only_is_distinct_from_query_only(): void
    {
        $q = UiFormDemoSubmissionListCriteria::fromRequest('platform.demo.storeContactDb', null, null);
        $a = UiFormDemoSubmissionListCriteria::fromRequest(null, 'platform.demo.storeContactDb', null);
        self::assertNotSame($q->fingerprint(), $a->fingerprint());
    }

    // ---------------------------------------------------------------
    // Sort-slice additions — server-owned allow-listed sorting.
    // ---------------------------------------------------------------

    #[Test]
    public function default_sort_is_submitted_at_desc(): void
    {
        $c = UiFormDemoSubmissionListCriteria::fromRequest(null, null, null);
        self::assertSame('submittedAt_desc', $c->sort->token);
        self::assertSame('submitted_at', $c->sort->field);
        self::assertSame('desc', $c->sort->direction);
        self::assertTrue($c->sort->isDefault());
    }

    #[Test]
    public function explicit_default_sort_token_keeps_isDefault_true(): void
    {
        $c = UiFormDemoSubmissionListCriteria::fromRequest(null, null, null, 'submittedAt_desc');
        self::assertSame('submittedAt_desc', $c->sort->token);
        self::assertTrue($c->isDefault());
        self::assertNull($c->fingerprint());
    }

    #[Test]
    public function ascending_sort_token_flips_direction_and_marks_non_default(): void
    {
        $c = UiFormDemoSubmissionListCriteria::fromRequest(null, null, null, 'submittedAt_asc');
        self::assertSame('submittedAt_asc', $c->sort->token);
        self::assertSame('asc', $c->sort->direction);
        self::assertFalse($c->isDefault());
        self::assertNotNull($c->fingerprint());
    }

    #[Test]
    public function unknown_sort_token_throws_invalid_sort(): void
    {
        try {
            UiFormDemoSubmissionListCriteria::fromRequest(null, null, null, 'contactName_desc');
            self::fail('Expected invalid_sort exception');
        } catch (UiFormDemoSubmissionSearchException $e) {
            self::assertSame('invalid_sort', $e->reasonCode);
            self::assertSame('Sort option is invalid.', $e->getMessage());
            self::assertStringNotContainsString('contactName_desc', $e->getMessage());
        }
    }

    #[Test]
    public function fingerprint_differs_across_sort_directions(): void
    {
        $desc = UiFormDemoSubmissionListCriteria::fromRequest('alpha', null, null, 'submittedAt_desc');
        $asc  = UiFormDemoSubmissionListCriteria::fromRequest('alpha', null, null, 'submittedAt_asc');
        self::assertNotSame($desc->fingerprint(), $asc->fingerprint());
    }

    #[Test]
    public function non_default_sort_alone_makes_fingerprint_non_null(): void
    {
        // Unfiltered (no q, no action) but sort diverges from default
        // → isUnfiltered() stays true (kept for template copy) but
        // isDefault() is false so fingerprint is non-null and the
        // cursor binds to the sort token.
        $c = UiFormDemoSubmissionListCriteria::fromRequest(null, null, null, 'submittedAt_asc');
        self::assertTrue($c->isUnfiltered());
        self::assertFalse($c->isDefault());
        self::assertNotNull($c->fingerprint());
    }

    #[Test]
    public function fingerprint_is_stable_for_same_canonical_input(): void
    {
        $a = UiFormDemoSubmissionListCriteria::fromRequest('Hello', 'platform.demo.storeContactDb', null, 'submittedAt_asc');
        $b = UiFormDemoSubmissionListCriteria::fromRequest('HELLO', 'platform.demo.storeContactDb', null, 'submittedAt_asc');
        self::assertSame($a->fingerprint(), $b->fingerprint());
    }

    #[Test]
    public function fingerprint_canonical_algorithm_includes_sort_token(): void
    {
        // Pin the algorithm so cross-listing collisions cannot
        // accidentally happen and so a refactor that drops the sort
        // segment from the canonical input is caught here.
        $c = UiFormDemoSubmissionListCriteria::fromRequest('hello', 'platform.demo.storeContactDb', null, 'submittedAt_asc');
        $expected = substr(hash('sha256', 'hello|platform.demo.storeContactDb|submittedAt_asc'), 0, 16);
        self::assertSame($expected, $c->fingerprint());
    }
}
