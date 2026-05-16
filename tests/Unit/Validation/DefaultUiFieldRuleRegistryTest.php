<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Validation\DefaultUiFieldRuleRegistry;
use Semitexa\PlatformUi\Application\Service\Validation\Rule\MaxLengthRule;
use Semitexa\PlatformUi\Application\Service\Validation\Rule\MinLengthRule;
use Semitexa\PlatformUi\Application\Service\Validation\Rule\RequiredRule;
use Semitexa\PlatformUi\Domain\Exception\UiFieldValidationRuleException;
use Semitexa\PlatformUi\Domain\Model\Event\UiFieldRuleSpec;

/**
 * Pins the contract the rest of the validation stack relies on:
 *   - which names exist;
 *   - which params each name requires;
 *   - the failure mode for unknown / malformed specs (typed exception
 *     with a safe message — no class FQCN leak).
 *
 * Custom registries are expected to honour the same contract for
 * built-ins they delegate to.
 */
final class DefaultUiFieldRuleRegistryTest extends TestCase
{
    #[Test]
    public function known_rule_names_lists_four_built_ins(): void
    {
        self::assertSame(
            [
                RequiredRule::NAME,
                MinLengthRule::NAME,
                MaxLengthRule::NAME,
                \Semitexa\PlatformUi\Application\Service\Validation\Rule\SameAsFieldRule::NAME,
            ],
            (new DefaultUiFieldRuleRegistry())->knownRuleNames(),
        );
    }

    #[Test]
    public function resolves_required_to_RequiredRule(): void
    {
        $rule = (new DefaultUiFieldRuleRegistry())
            ->resolve(new UiFieldRuleSpec(RequiredRule::NAME));
        self::assertInstanceOf(RequiredRule::class, $rule);
    }

    #[Test]
    public function resolves_min_length_to_MinLengthRule(): void
    {
        $rule = (new DefaultUiFieldRuleRegistry())
            ->resolve(new UiFieldRuleSpec(MinLengthRule::NAME, [3]));
        self::assertInstanceOf(MinLengthRule::class, $rule);
    }

    #[Test]
    public function resolves_max_length_to_MaxLengthRule(): void
    {
        $rule = (new DefaultUiFieldRuleRegistry())
            ->resolve(new UiFieldRuleSpec(MaxLengthRule::NAME, [20]));
        self::assertInstanceOf(MaxLengthRule::class, $rule);
    }

    #[Test]
    public function rejects_unknown_name(): void
    {
        $this->expectException(UiFieldValidationRuleException::class);
        $this->expectExceptionMessageMatches('/Unknown rule "evilRule"/');
        (new DefaultUiFieldRuleRegistry())
            ->resolve(new UiFieldRuleSpec('evilRule'));
    }

    #[Test]
    public function rejects_required_with_unexpected_params(): void
    {
        $this->expectException(UiFieldValidationRuleException::class);
        (new DefaultUiFieldRuleRegistry())
            ->resolve(new UiFieldRuleSpec(RequiredRule::NAME, ['extra']));
    }

    #[Test]
    public function rejects_min_length_with_zero_params(): void
    {
        $this->expectException(UiFieldValidationRuleException::class);
        (new DefaultUiFieldRuleRegistry())
            ->resolve(new UiFieldRuleSpec(MinLengthRule::NAME));
    }

    #[Test]
    public function rejects_min_length_with_non_integer_param(): void
    {
        $this->expectException(UiFieldValidationRuleException::class);
        $this->expectExceptionMessageMatches('/must be an integer/');
        (new DefaultUiFieldRuleRegistry())
            ->resolve(new UiFieldRuleSpec(MinLengthRule::NAME, [1.5]));
    }

    #[Test]
    public function accepts_digit_string_for_min_length_param(): void
    {
        // JSON round-trips may stringify ints; the registry accepts
        // the digit-only string and coerces.
        $rule = (new DefaultUiFieldRuleRegistry())
            ->resolve(new UiFieldRuleSpec(MinLengthRule::NAME, ['3']));
        self::assertInstanceOf(MinLengthRule::class, $rule);
    }

    #[Test]
    public function rule_constructor_failure_is_wrapped(): void
    {
        // MinLengthRule rejects negative min via
        // InvalidArgumentException; the registry must wrap this as a
        // UiFieldValidationRuleException so the parser path's
        // failure model is consistent.
        $this->expectException(UiFieldValidationRuleException::class);
        (new DefaultUiFieldRuleRegistry())
            ->resolve(new UiFieldRuleSpec(MinLengthRule::NAME, [-1]));
    }

    #[Test]
    public function resolves_same_as_field_to_SameAsFieldRule(): void
    {
        $rule = (new DefaultUiFieldRuleRegistry())
            ->resolve(new UiFieldRuleSpec('sameAsField', ['password']));
        self::assertInstanceOf(
            \Semitexa\PlatformUi\Application\Service\Validation\Rule\SameAsFieldRule::class,
            $rule,
        );
    }

    #[Test]
    public function same_as_field_accepts_custom_message_param(): void
    {
        $rule = (new DefaultUiFieldRuleRegistry())
            ->resolve(new UiFieldRuleSpec('sameAsField', ['access_code', 'Codes must match.']));
        self::assertInstanceOf(
            \Semitexa\PlatformUi\Application\Service\Validation\Rule\SameAsFieldRule::class,
            $rule,
        );
    }

    #[Test]
    public function rejects_same_as_field_with_zero_params(): void
    {
        $this->expectException(UiFieldValidationRuleException::class);
        $this->expectExceptionMessage('Rule "sameAsField" expects 1–2 parameter(s), got 0.');
        (new DefaultUiFieldRuleRegistry())
            ->resolve(new UiFieldRuleSpec('sameAsField', []));
    }

    #[Test]
    public function rejects_same_as_field_with_too_many_params(): void
    {
        $this->expectException(UiFieldValidationRuleException::class);
        $this->expectExceptionMessage('Rule "sameAsField" expects 1–2 parameter(s), got 3.');
        (new DefaultUiFieldRuleRegistry())
            ->resolve(new UiFieldRuleSpec('sameAsField', ['a', 'b', 'c']));
    }

    #[Test]
    public function rejects_same_as_field_with_unsafe_field_name(): void
    {
        $this->expectException(UiFieldValidationRuleException::class);
        $this->expectExceptionMessage('parameter 0 must be a safe field-name identifier');
        (new DefaultUiFieldRuleRegistry())
            ->resolve(new UiFieldRuleSpec('sameAsField', ['evil" onerror=1']));
    }

    #[Test]
    public function rejects_same_as_field_with_empty_custom_message(): void
    {
        $this->expectException(UiFieldValidationRuleException::class);
        $this->expectExceptionMessage('parameter 1 (custom message) must be a non-empty string');
        (new DefaultUiFieldRuleRegistry())
            ->resolve(new UiFieldRuleSpec('sameAsField', ['access_code', '']));
    }

    #[Test]
    public function existing_rules_keep_strict_fixed_arity_after_variadic_introduction(): void
    {
        // Regression: introducing the variadic [min,max] arity contract
        // for sameAsField must NOT relax the rigid arity of the three
        // previously fixed-arity rules.
        $registry = new DefaultUiFieldRuleRegistry();
        try {
            $registry->resolve(new UiFieldRuleSpec(RequiredRule::NAME, ['extra']));
            self::fail('required rejected zero-arg violation');
        } catch (UiFieldValidationRuleException $e) {
            self::assertStringContainsString('expects 0 parameter', $e->getMessage());
        }
        try {
            $registry->resolve(new UiFieldRuleSpec(MinLengthRule::NAME, []));
            self::fail('minLength rejected no params');
        } catch (UiFieldValidationRuleException $e) {
            self::assertStringContainsString('expects 1 parameter', $e->getMessage());
        }
    }

    #[Test]
    public function error_messages_do_not_leak_class_fqcn(): void
    {
        try {
            (new DefaultUiFieldRuleRegistry())
                ->resolve(new UiFieldRuleSpec('evilRule'));
            self::fail('Expected exception');
        } catch (UiFieldValidationRuleException $e) {
            self::assertStringNotContainsString('Semitexa\\', $e->getMessage());
            self::assertStringNotContainsString('DefaultUiFieldRuleRegistry', $e->getMessage());
        }
    }
}
