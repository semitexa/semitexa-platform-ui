<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Validation\Rule\MaxLengthRule;
use Semitexa\PlatformUi\Application\Service\Validation\Rule\MinLengthRule;
use Semitexa\PlatformUi\Application\Service\Validation\Rule\RequiredRule;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationContext;

final class RuleUnitTest extends TestCase
{
    private function ctx(): UiFieldValidationContext
    {
        return new UiFieldValidationContext(
            componentName: 'platform.field',
            instanceId:    'uci_test_0001',
            fieldName:     'input',
        );
    }

    #[Test]
    public function required_passes_non_empty_string(): void
    {
        self::assertNull((new RequiredRule())->validate('abc', $this->ctx()));
    }

    #[Test]
    public function required_fails_empty_string(): void
    {
        $r = (new RequiredRule())->validate('', $this->ctx());
        self::assertNotNull($r);
        self::assertFalse($r->isValid());
        self::assertSame('This field is required.', $r->message);
    }

    #[Test]
    public function required_fails_whitespace_only(): void
    {
        self::assertNotNull((new RequiredRule())->validate("   \t  ", $this->ctx()));
    }

    #[Test]
    public function min_length_passes_when_long_enough(): void
    {
        self::assertNull((new MinLengthRule(3))->validate('abc', $this->ctx()));
        self::assertNull((new MinLengthRule(3))->validate('hello world', $this->ctx()));
    }

    #[Test]
    public function min_length_fails_when_too_short(): void
    {
        $r = (new MinLengthRule(3))->validate('ab', $this->ctx());
        self::assertNotNull($r);
        self::assertFalse($r->isValid());
        self::assertSame('Please enter at least 3 characters.', $r->message);
    }

    #[Test]
    public function min_length_passes_empty_string_to_defer_to_required(): void
    {
        // Empty values pass minLength so callers must pair with
        // required for an "empty AND too short" reject.
        self::assertNull((new MinLengthRule(3))->validate('', $this->ctx()));
        self::assertNull((new MinLengthRule(3))->validate('   ', $this->ctx()));
    }

    #[Test]
    public function min_length_counts_mb_characters_not_bytes(): void
    {
        // 'éé' = 4 bytes / 2 chars → fails minLength 3.
        // 'ééé' = 6 bytes / 3 chars → passes minLength 3.
        self::assertNotNull((new MinLengthRule(3))->validate('éé', $this->ctx()));
        self::assertNull((new MinLengthRule(3))->validate('ééé', $this->ctx()));
    }

    #[Test]
    public function min_length_rejects_negative_min(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MinLengthRule(-1);
    }

    #[Test]
    public function max_length_passes_under_or_equal(): void
    {
        self::assertNull((new MaxLengthRule(5))->validate('hello', $this->ctx()));
        self::assertNull((new MaxLengthRule(5))->validate('', $this->ctx()));
        self::assertNull((new MaxLengthRule(5))->validate('abc', $this->ctx()));
    }

    #[Test]
    public function max_length_fails_when_over(): void
    {
        $r = (new MaxLengthRule(5))->validate('hello!', $this->ctx());
        self::assertNotNull($r);
        self::assertSame('Please enter no more than 5 characters.', $r->message);
    }

    #[Test]
    public function max_length_does_not_trim(): void
    {
        // 6 trailing spaces → 8 chars total → over max 5 → fail.
        self::assertNotNull((new MaxLengthRule(5))->validate('ab      ', $this->ctx()));
    }

    #[Test]
    public function max_length_rejects_negative_max(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MaxLengthRule(-1);
    }
}
