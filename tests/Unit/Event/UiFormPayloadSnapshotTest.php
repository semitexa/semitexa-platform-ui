<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Event\UiFormPayloadSnapshot;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionBadRequestException;

/**
 * Sanitiser tests for the client-submitted `payload.form.values`
 * snapshot. The extractor runs at the dispatch boundary AFTER
 * UiPayloadFieldGuard has scrubbed routing-flavored keys (and after
 * that guard has rejected any `payload.form.rules` / `payload.form.cfg`
 * smuggling attempt). These tests pin the extractor's shape
 * constraints — safe-identifier keys, scalar-or-null values, bounded
 * count + length — and the resulting 400 reason codes.
 */
final class UiFormPayloadSnapshotTest extends TestCase
{
    private UiFormPayloadSnapshot $extractor;

    protected function setUp(): void
    {
        $this->extractor = new UiFormPayloadSnapshot();
    }

    #[Test]
    public function missing_form_key_returns_empty_map(): void
    {
        self::assertSame([], $this->extractor->extract([]));
        self::assertSame([], $this->extractor->extract(['value' => 'x']));
    }

    #[Test]
    public function null_form_returns_empty_map(): void
    {
        self::assertSame([], $this->extractor->extract(['form' => null]));
    }

    #[Test]
    public function missing_or_null_values_returns_empty_map(): void
    {
        self::assertSame([], $this->extractor->extract(['form' => []]));
        self::assertSame([], $this->extractor->extract(['form' => ['values' => null]]));
    }

    #[Test]
    public function returns_safe_scalar_map(): void
    {
        $out = $this->extractor->extract([
            'form' => [
                'values' => [
                    'access_code' => 'abcd',
                    'confirm_access_code' => 'abcd',
                    'numeric' => 42,
                    'flag' => true,
                    'missing' => null,
                ],
            ],
        ]);
        self::assertSame([
            'access_code' => 'abcd',
            'confirm_access_code' => 'abcd',
            'numeric' => 42,
            'flag' => true,
            'missing' => null,
        ], $out);
    }

    #[Test]
    public function form_must_be_an_object_not_list(): void
    {
        $this->expectExceptionObject(new UiInteractionBadRequestException(
            'invalid_form_snapshot',
            'The "payload.form" field must be a JSON object when provided.',
        ));
        $this->extractor->extract(['form' => ['a', 'b']]);
    }

    #[Test]
    public function values_must_be_an_object_not_list(): void
    {
        try {
            $this->extractor->extract(['form' => ['values' => ['a', 'b']]]);
            self::fail('values-as-list should raise 400');
        } catch (UiInteractionBadRequestException $e) {
            self::assertSame('invalid_form_snapshot', $e->reason);
            self::assertStringContainsString('mapping field names', $e->getMessage());
        }
    }

    #[Test]
    public function values_must_be_an_object_not_scalar(): void
    {
        try {
            $this->extractor->extract(['form' => ['values' => 'oops']]);
            self::fail();
        } catch (UiInteractionBadRequestException $e) {
            self::assertSame('invalid_form_snapshot', $e->reason);
        }
    }

    #[Test]
    public function keys_must_match_safe_identifier(): void
    {
        // All these cases produce a non-list assoc array (so they
        // survive the values-shape check) but carry a key that fails
        // the safe-identifier regex.
        $cases = [
            'starts_with_digit'   => ['form' => ['values' => ['1bad' => 'x']]],
            'contains_space'      => ['form' => ['values' => ['evil name' => 'x']]],
            'contains_angle_lt'   => ['form' => ['values' => ['<script>' => 'x']]],
            'contains_double_q'   => ['form' => ['values' => ['name"' => 'x']]],
            'starts_with_dot'     => ['form' => ['values' => ['.dotfile' => 'x']]],
        ];
        foreach ($cases as $label => $case) {
            try {
                $this->extractor->extract($case);
                self::fail($label . ' case should have raised: ' . json_encode($case));
            } catch (UiInteractionBadRequestException $e) {
                self::assertSame('invalid_form_snapshot_key', $e->reason, $label);
            }
        }
    }

    #[Test]
    public function hyphenated_identifier_is_accepted(): void
    {
        $out = $this->extractor->extract([
            'form' => ['values' => ['data-flavor' => 'x']],
        ]);
        self::assertSame(['data-flavor' => 'x'], $out);
    }

    #[Test]
    public function array_value_rejected(): void
    {
        try {
            $this->extractor->extract(['form' => ['values' => ['multi' => [1, 2]]]]);
            self::fail();
        } catch (UiInteractionBadRequestException $e) {
            self::assertSame('invalid_form_snapshot_value', $e->reason);
            self::assertStringContainsString('arrays and objects are rejected', $e->getMessage());
        }
    }

    #[Test]
    public function object_value_rejected(): void
    {
        try {
            $this->extractor->extract(['form' => ['values' => ['obj' => ['nested' => 'x']]]]);
            self::fail();
        } catch (UiInteractionBadRequestException $e) {
            self::assertSame('invalid_form_snapshot_value', $e->reason);
        }
    }

    #[Test]
    public function too_many_fields_rejected(): void
    {
        $values = [];
        for ($i = 0; $i <= UiFormPayloadSnapshot::MAX_FIELDS; $i++) {
            $values['field_' . $i] = 'v';
        }
        try {
            $this->extractor->extract(['form' => ['values' => $values]]);
            self::fail();
        } catch (UiInteractionBadRequestException $e) {
            self::assertSame('form_snapshot_too_large', $e->reason);
        }
    }

    #[Test]
    public function too_long_value_rejected(): void
    {
        $tooLong = str_repeat('a', UiFormPayloadSnapshot::MAX_VALUE_LENGTH + 1);
        try {
            $this->extractor->extract(['form' => ['values' => ['k' => $tooLong]]]);
            self::fail();
        } catch (UiInteractionBadRequestException $e) {
            self::assertSame('form_snapshot_value_too_long', $e->reason);
        }
    }

    #[Test]
    public function max_length_value_accepted(): void
    {
        $atLimit = str_repeat('a', UiFormPayloadSnapshot::MAX_VALUE_LENGTH);
        $out = $this->extractor->extract(['form' => ['values' => ['k' => $atLimit]]]);
        self::assertSame(['k' => $atLimit], $out);
    }
}
