<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Validation\Rule\SameAsFieldRule;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiFieldValidationResult;

final class SameAsFieldRuleTest extends TestCase
{
    /**
     * @param array<string, scalar|null> $formValues
     */
    private function ctx(array $formValues): UiFieldValidationContext
    {
        return new UiFieldValidationContext(
            componentName: 'platform.field',
            instanceId:    'uci_test',
            fieldName:     'input',
            formValues:    $formValues,
        );
    }

    #[Test]
    public function passes_when_values_match(): void
    {
        $rule = new SameAsFieldRule('access_code');
        self::assertNull($rule->validate('abcd', $this->ctx(['access_code' => 'abcd'])));
    }

    #[Test]
    public function fails_with_default_message_when_values_differ(): void
    {
        $rule = new SameAsFieldRule('access_code');
        $result = $rule->validate('zzzz', $this->ctx(['access_code' => 'abcd']));
        self::assertNotNull($result);
        self::assertSame(UiFieldValidationResult::STATE_INVALID, $result->state);
        self::assertSame('Values must match.', $result->message);
    }

    #[Test]
    public function fails_with_custom_message_when_values_differ(): void
    {
        $rule = new SameAsFieldRule('access_code', 'Codes must match.');
        $result = $rule->validate('zzzz', $this->ctx(['access_code' => 'abcd']));
        self::assertNotNull($result);
        self::assertSame('Codes must match.', $result->message);
    }

    #[Test]
    public function passes_when_both_values_empty_and_sibling_present(): void
    {
        // Per documented behaviour: empty current AND empty sibling
        // passes — pair with required to reject empties.
        $rule = new SameAsFieldRule('access_code');
        self::assertNull($rule->validate('', $this->ctx(['access_code' => ''])));
        self::assertNull($rule->validate('   ', $this->ctx(['access_code' => "\t"])));
    }

    #[Test]
    public function passes_when_current_empty_and_sibling_missing(): void
    {
        $rule = new SameAsFieldRule('access_code');
        self::assertNull($rule->validate('', $this->ctx([])));
        self::assertNull($rule->validate('   ', $this->ctx([])));
    }

    #[Test]
    public function fails_with_sentinel_message_when_sibling_missing_and_current_non_empty(): void
    {
        $rule = new SameAsFieldRule('access_code', 'Codes must match.');
        $result = $rule->validate('abcd', $this->ctx([]));
        self::assertNotNull($result);
        self::assertSame(SameAsFieldRule::MISSING_SIBLING_MESSAGE, $result->message);
        // Sentinel never gets overridden by the custom mismatch message.
        self::assertNotSame('Codes must match.', $result->message);
    }

    #[Test]
    public function trims_before_comparing(): void
    {
        $rule = new SameAsFieldRule('access_code');
        self::assertNull($rule->validate('  abcd  ', $this->ctx(['access_code' => 'abcd'])));
        self::assertNull($rule->validate('abcd', $this->ctx(['access_code' => "abcd\n"])));
    }

    #[Test]
    public function compares_scalars_as_strings(): void
    {
        $rule = new SameAsFieldRule('access_code');
        // JSON transport may stringify; rule treats them equal.
        self::assertNull($rule->validate(1, $this->ctx(['access_code' => '1'])));
        self::assertNull($rule->validate('1', $this->ctx(['access_code' => 1])));
    }

    #[Test]
    public function null_sibling_treated_as_empty_value_not_missing(): void
    {
        // `null` is a legal scalar/null sentinel in the snapshot — it
        // is "present, empty" rather than "missing".
        $rule = new SameAsFieldRule('access_code');
        // Both empty → pass.
        self::assertNull($rule->validate('', $this->ctx(['access_code' => null])));
        // Current non-empty, sibling null/empty → mismatch with default message.
        $r = $rule->validate('abcd', $this->ctx(['access_code' => null]));
        self::assertNotNull($r);
        self::assertSame('Values must match.', $r->message);
    }

    #[Test]
    public function constructor_rejects_unsafe_field_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SameAsFieldRule('evil" onerror=1');
    }

    #[Test]
    public function constructor_rejects_empty_custom_message(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SameAsFieldRule('access_code', '');
    }
}
