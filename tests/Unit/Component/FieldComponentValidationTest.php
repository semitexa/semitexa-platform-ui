<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Component;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Component\Builtin\FieldComponent;
use Semitexa\PlatformUi\Domain\Model\Event\UiFieldValidationResult;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionEvent;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionResult;
use Semitexa\PlatformUi\Domain\Model\Event\UiResponsePatch;

/**
 * Pure-PHP tests for the FieldComponent's demo validation rule.
 *
 * The `validate()` method is intentionally surfaced as a public helper
 * so tests can pin the rule without round-tripping through the
 * dispatcher. The handler test below proves the patches list flows
 * from validate() through onInputChanged() into UiInteractionResult.
 */
final class FieldComponentValidationTest extends TestCase
{
    private function event(string $value = '', string $instance = 'uci_field_test_01'): UiInteractionEvent
    {
        return new UiInteractionEvent(
            componentName: 'platform.field',
            instanceId:    $instance,
            partName:      'input',
            eventName:     'change',
            updatesPath:   null,
            payload:       ['value' => $value],
            issuedAt:      time(),
            expiresAt:     time() + 60,
            claims:        [],
            dispatchId:    'ui_evt_test_0001',
        );
    }

    #[Test]
    public function empty_value_is_invalid_with_required_message(): void
    {
        $r = (new FieldComponent())->validate('');
        self::assertFalse($r->isValid());
        self::assertSame(FieldComponent::VALIDATION_MESSAGE_REQUIRED, $r->message);
    }

    #[Test]
    public function whitespace_only_value_is_invalid_with_required_message(): void
    {
        $r = (new FieldComponent())->validate("   \t  ");
        self::assertFalse($r->isValid());
        self::assertSame(FieldComponent::VALIDATION_MESSAGE_REQUIRED, $r->message);
    }

    #[Test]
    public function short_value_is_invalid_with_too_short_message(): void
    {
        $r = (new FieldComponent())->validate('ab');
        self::assertFalse($r->isValid());
        self::assertSame(FieldComponent::VALIDATION_MESSAGE_TOO_SHORT, $r->message);
    }

    #[Test]
    public function boundary_value_three_chars_is_valid(): void
    {
        $r = (new FieldComponent())->validate('abc');
        self::assertTrue($r->isValid());
        self::assertSame(FieldComponent::VALIDATION_MESSAGE_OK, $r->message);
    }

    #[Test]
    public function long_value_is_valid(): void
    {
        $r = (new FieldComponent())->validate('taras@example.com');
        self::assertTrue($r->isValid());
    }

    #[Test]
    public function multibyte_length_uses_character_count_not_byte_count(): void
    {
        // 'éé' is 4 bytes but 2 characters → still too short.
        // 'ééé' is 6 bytes / 3 chars → valid by the rule.
        $short = (new FieldComponent())->validate('éé');
        $boundary = (new FieldComponent())->validate('ééé');
        self::assertFalse($short->isValid());
        self::assertTrue($boundary->isValid());
    }

    #[Test]
    public function handler_invalid_emits_three_validation_patches_plus_server_ack(): void
    {
        $result = (new FieldComponent())->onInputChanged($this->event(''));
        self::assertInstanceOf(UiInteractionResult::class, $result);
        self::assertSame(UiInteractionResult::KIND_PATCH, $result->kind);
        self::assertCount(4, $result->patches);

        // aria-invalid=true, ui-state=invalid, validation-message, server-ack
        self::assertSame('aria-invalid', $result->patches[0]->attribute);
        self::assertSame('true', $result->patches[0]->value);
        self::assertSame('ui-state', $result->patches[1]->attribute);
        self::assertSame('invalid', $result->patches[1]->value);
        self::assertSame(UiResponsePatch::OP_SET_TEXT, $result->patches[2]->op);
        self::assertSame('validation-message', $result->patches[2]->targetName);
        self::assertSame(FieldComponent::VALIDATION_MESSAGE_REQUIRED, $result->patches[2]->value);
        self::assertSame(UiResponsePatch::OP_SET_TEXT, $result->patches[3]->op);
        self::assertSame('server-ack', $result->patches[3]->targetName);
    }

    #[Test]
    public function handler_valid_emits_aria_removal_and_positive_message(): void
    {
        $result = (new FieldComponent())->onInputChanged($this->event('taras'));
        self::assertCount(4, $result->patches);
        // aria-invalid is REMOVED (null value).
        self::assertSame('aria-invalid', $result->patches[0]->attribute);
        self::assertNull($result->patches[0]->value);
        // ui-state flips to valid.
        self::assertSame('ui-state', $result->patches[1]->attribute);
        self::assertSame('valid', $result->patches[1]->value);
        // Positive message.
        self::assertSame(FieldComponent::VALIDATION_MESSAGE_OK, $result->patches[2]->value);
        // server-ack is the trailing echo.
        self::assertSame('Server received: taras', $result->patches[3]->value);
    }

    #[Test]
    public function all_patches_target_same_signed_instance(): void
    {
        $result = (new FieldComponent())->onInputChanged($this->event('boom-too-short', 'uci_specific_AAA'));
        foreach ($result->patches as $patch) {
            self::assertSame('uci_specific_AAA', $patch->targetInstance);
        }
    }

    #[Test]
    public function handler_debug_carries_validation_state(): void
    {
        $r = (new FieldComponent())->onInputChanged($this->event('ab'));
        self::assertSame('invalid', $r->debug['validation']['state']);
        self::assertSame(FieldComponent::VALIDATION_MESSAGE_TOO_SHORT, $r->debug['validation']['message']);
    }

    #[Test]
    public function validate_constant_thresholds_are_stable(): void
    {
        // Regression — docs + playground copy quote these values
        // verbatim. If they change, callers need updating.
        self::assertSame(3, FieldComponent::VALIDATION_MIN_LENGTH);
        self::assertSame('This field is required.', FieldComponent::VALIDATION_MESSAGE_REQUIRED);
        self::assertSame('Please enter at least 3 characters.', FieldComponent::VALIDATION_MESSAGE_TOO_SHORT);
        self::assertSame('Looks good.', FieldComponent::VALIDATION_MESSAGE_OK);
    }

    #[Test]
    public function validation_result_factories_match_handler_output(): void
    {
        // Sanity: the handler's validate() should return the same
        // shape the factories produce directly.
        $empty = (new FieldComponent())->validate('');
        $expected = UiFieldValidationResult::invalid(FieldComponent::VALIDATION_MESSAGE_REQUIRED);
        self::assertSame($empty->state, $expected->state);
        self::assertSame($empty->message, $expected->message);
    }
}
