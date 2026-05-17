<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Submit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Domain\Exception\UiFormDemoSubmissionSearchException;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormDemoSubmissionSort;

/**
 * Server-owned sort token allow-list for the diagnostic
 * demo-submissions grid. Direct parallel to the lead sort VO test:
 *
 *   - null / empty / whitespace input falls back to the default
 *     token (`submittedAt_desc`);
 *   - unknown tokens throw `invalid_sort`;
 *   - the canonical (field, direction) pair never reads back the
 *     raw client string — both come from the closed allow-list;
 *   - contact-field tokens are NOT accepted in this slice
 *     (deliberately deferred — sorting on the JSON column would
 *     defeat the (submitted_at, id) index).
 */
final class UiFormDemoSubmissionSortTest extends TestCase
{
    #[Test]
    public function null_falls_back_to_default(): void
    {
        $s = UiFormDemoSubmissionSort::fromRequest(null);
        self::assertSame('submittedAt_desc', $s->token);
        self::assertSame('submitted_at', $s->field);
        self::assertSame('desc', $s->direction);
        self::assertTrue($s->isDefault());
    }

    #[Test]
    public function empty_or_whitespace_falls_back_to_default(): void
    {
        foreach (['', '   ', "\t\n"] as $raw) {
            $s = UiFormDemoSubmissionSort::fromRequest($raw);
            self::assertSame('submittedAt_desc', $s->token, "raw '{$raw}' must normalise to default");
        }
    }

    #[Test]
    public function accepts_submitted_at_desc(): void
    {
        $s = UiFormDemoSubmissionSort::fromRequest('submittedAt_desc');
        self::assertSame('submittedAt_desc', $s->token);
        self::assertSame('desc', $s->direction);
        self::assertTrue($s->isDefault());
    }

    #[Test]
    public function accepts_submitted_at_asc(): void
    {
        $s = UiFormDemoSubmissionSort::fromRequest('submittedAt_asc');
        self::assertSame('submittedAt_asc', $s->token);
        self::assertSame('asc', $s->direction);
        self::assertFalse($s->isDefault());
    }

    #[Test]
    public function rejects_contact_field_tokens_as_deferred(): void
    {
        // Slice deliberately defers contact-field sorting — the JSON
        // column does not support an indexed ORDER BY, and the
        // operator surface is fine with `submittedAt_*` for now.
        foreach (
            [
                'contactName_asc', 'contactName_desc',
                'contactTopic_asc', 'contactTopic_desc',
                'contactMessage_asc', 'contactMessage_desc',
            ] as $raw
        ) {
            try {
                UiFormDemoSubmissionSort::fromRequest($raw);
                self::fail("Expected invalid_sort for '{$raw}'");
            } catch (UiFormDemoSubmissionSearchException $e) {
                self::assertSame('invalid_sort', $e->reasonCode);
            }
        }
    }

    #[Test]
    public function rejects_id_tokens(): void
    {
        foreach (['id_asc', 'id_desc'] as $raw) {
            try {
                UiFormDemoSubmissionSort::fromRequest($raw);
                self::fail("Expected invalid_sort for '{$raw}'");
            } catch (UiFormDemoSubmissionSearchException $e) {
                self::assertSame('invalid_sort', $e->reasonCode);
            }
        }
    }

    #[Test]
    public function rejects_random_garbage_and_does_not_echo_input(): void
    {
        foreach (['../etc/passwd', 'submitted_at', 'submittedAt', 'submittedAt_DESC', '1; DROP TABLE', '<script>alert(1)</script>'] as $raw) {
            try {
                UiFormDemoSubmissionSort::fromRequest($raw);
                self::fail("Expected invalid_sort for " . var_export($raw, true));
            } catch (UiFormDemoSubmissionSearchException $e) {
                self::assertSame('invalid_sort', $e->reasonCode);
                self::assertSame('Sort option is invalid.', $e->getMessage());
                self::assertStringNotContainsString($raw, $e->getMessage());
            }
        }
    }

    #[Test]
    public function allowed_tokens_snapshot(): void
    {
        // Doc-shape pin: the slice exposes exactly the two
        // `submittedAt_*` tokens. Adding more to the allow-list is
        // a separate slice (repo cursor branch + tests required).
        self::assertSame(
            ['submittedAt_desc', 'submittedAt_asc'],
            UiFormDemoSubmissionSort::allowedTokens(),
        );
    }
}
