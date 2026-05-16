<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Validation\Rule\MaxLengthRule;
use Semitexa\PlatformUi\Application\Service\Validation\Rule\MinLengthRule;
use Semitexa\PlatformUi\Application\Service\Validation\Rule\RequiredRule;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationContext;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidator;

final class UiFieldValidatorTest extends TestCase
{
    private function ctx(): UiFieldValidationContext
    {
        return new UiFieldValidationContext(
            componentName: 'platform.field',
            instanceId:    'uci_t',
            fieldName:     'input',
        );
    }

    #[Test]
    public function zero_rules_yields_valid_with_silent_pass(): void
    {
        $r = (new UiFieldValidator())->validate('whatever', [], $this->ctx());
        self::assertTrue($r->isValid());
    }

    #[Test]
    public function all_rules_pass_yields_default_success_message(): void
    {
        $r = (new UiFieldValidator())->validate('hello', [
            new RequiredRule(),
            new MinLengthRule(3),
            new MaxLengthRule(50),
        ], $this->ctx());
        self::assertTrue($r->isValid());
        self::assertSame('Looks good.', $r->message);
    }

    #[Test]
    public function caller_can_override_success_message(): void
    {
        $r = (new UiFieldValidator())->validate('hello', [
            new RequiredRule(),
        ], $this->ctx(), successMessage: 'Custom OK.');
        self::assertSame('Custom OK.', $r->message);
    }

    #[Test]
    public function first_failing_rule_wins(): void
    {
        // Empty value: required would fail first; minLength would also
        // fail. Required-first ordering should produce the required
        // diagnostic, not the minLength one.
        $r = (new UiFieldValidator())->validate('', [
            new RequiredRule(),
            new MinLengthRule(3),
        ], $this->ctx());
        self::assertFalse($r->isValid());
        self::assertSame('This field is required.', $r->message);
    }

    #[Test]
    public function rule_order_matters_for_diagnostic(): void
    {
        // Reverse the previous order: minLength runs first but passes
        // on empty values (it defers to required), so required's
        // failure still wins. Demonstrates the documented "minLength
        // does not reject empties" contract.
        $r = (new UiFieldValidator())->validate('', [
            new MinLengthRule(3),
            new RequiredRule(),
        ], $this->ctx());
        self::assertFalse($r->isValid());
        self::assertSame('This field is required.', $r->message);
    }

    #[Test]
    public function later_failing_rule_wins_when_earlier_passes(): void
    {
        $r = (new UiFieldValidator())->validate('ab', [
            new RequiredRule(),       // passes ('ab' is non-empty)
            new MinLengthRule(3),     // fails (2 < 3)
        ], $this->ctx());
        self::assertFalse($r->isValid());
        self::assertSame('Please enter at least 3 characters.', $r->message);
    }

    #[Test]
    public function max_length_failure_is_caught(): void
    {
        $r = (new UiFieldValidator())->validate('hello, world!', [
            new RequiredRule(),
            new MaxLengthRule(5),
        ], $this->ctx());
        self::assertFalse($r->isValid());
        self::assertSame('Please enter no more than 5 characters.', $r->message);
    }

    #[Test]
    public function null_success_message_skips_text_patch(): void
    {
        // UiFieldValidationResult::valid(null) → toPatches() omits the
        // setText. Useful for handlers that want silent valid states.
        $r = (new UiFieldValidator())->validate('hello', [], $this->ctx(), successMessage: null);
        self::assertTrue($r->isValid());
        self::assertNull($r->message);
    }
}
