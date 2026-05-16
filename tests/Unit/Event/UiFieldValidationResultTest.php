<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Domain\Model\Event\UiFieldValidationResult;
use Semitexa\PlatformUi\Domain\Model\Event\UiResponsePatch;

/**
 * Pins the small value-object surface that drives FieldComponent's
 * validation patch list. Future slices may layer a richer state model
 * on top; this test set is the contract everything else builds on.
 */
final class UiFieldValidationResultTest extends TestCase
{
    #[Test]
    public function valid_factory_marks_state_valid(): void
    {
        $r = UiFieldValidationResult::valid('Looks good.');
        self::assertTrue($r->isValid());
        self::assertSame(UiFieldValidationResult::STATE_VALID, $r->state);
        self::assertSame('Looks good.', $r->message);
    }

    #[Test]
    public function valid_factory_accepts_null_message(): void
    {
        $r = UiFieldValidationResult::valid(null);
        self::assertTrue($r->isValid());
        self::assertNull($r->message);
    }

    #[Test]
    public function invalid_factory_requires_a_message(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UiFieldValidationResult::invalid('');
    }

    #[Test]
    public function invalid_factory_marks_state_invalid(): void
    {
        $r = UiFieldValidationResult::invalid('This field is required.');
        self::assertFalse($r->isValid());
        self::assertSame(UiFieldValidationResult::STATE_INVALID, $r->state);
        self::assertSame('This field is required.', $r->message);
    }

    #[Test]
    public function invalid_to_patches_emits_aria_ui_state_and_message(): void
    {
        $patches = UiFieldValidationResult::invalid('This field is required.')
            ->toPatches('uci_test_0000000001');
        self::assertCount(3, $patches);

        // 1. aria-invalid=true on the input UiPart.
        self::assertInstanceOf(UiResponsePatch::class, $patches[0]);
        self::assertSame(UiResponsePatch::OP_SET_ATTRIBUTE, $patches[0]->op);
        self::assertSame('aria-invalid', $patches[0]->attribute);
        self::assertSame('true', $patches[0]->value);
        self::assertSame('input', $patches[0]->targetPart);
        self::assertSame('uci_test_0000000001', $patches[0]->targetInstance);

        // 2. ui-state=invalid on the input UiPart.
        self::assertSame(UiResponsePatch::OP_SET_ATTRIBUTE, $patches[1]->op);
        self::assertSame('ui-state', $patches[1]->attribute);
        self::assertSame('invalid', $patches[1]->value);
        self::assertSame('input', $patches[1]->targetPart);

        // 3. setText on the validation-message named target.
        self::assertSame(UiResponsePatch::OP_SET_TEXT, $patches[2]->op);
        self::assertSame('validation-message', $patches[2]->targetName);
        self::assertSame('This field is required.', $patches[2]->value);
        self::assertNull($patches[2]->targetPart);
    }

    #[Test]
    public function valid_to_patches_removes_aria_invalid_and_sets_ui_state_valid(): void
    {
        $patches = UiFieldValidationResult::valid('Looks good.')
            ->toPatches('uci_test_0000000001');
        self::assertCount(3, $patches);

        // aria-invalid is REMOVED by setAttribute with null value.
        self::assertSame(UiResponsePatch::OP_SET_ATTRIBUTE, $patches[0]->op);
        self::assertSame('aria-invalid', $patches[0]->attribute);
        self::assertNull($patches[0]->value);

        // ui-state flips to 'valid'.
        self::assertSame('ui-state', $patches[1]->attribute);
        self::assertSame('valid', $patches[1]->value);

        // Message setText.
        self::assertSame(UiResponsePatch::OP_SET_TEXT, $patches[2]->op);
        self::assertSame('Looks good.', $patches[2]->value);
    }

    #[Test]
    public function valid_with_null_message_omits_message_patch(): void
    {
        $patches = UiFieldValidationResult::valid(null)
            ->toPatches('uci_test_0000000001');
        // Only aria-invalid + ui-state — no validation-message setText.
        self::assertCount(2, $patches);
        self::assertSame(UiResponsePatch::OP_SET_ATTRIBUTE, $patches[0]->op);
        self::assertSame(UiResponsePatch::OP_SET_ATTRIBUTE, $patches[1]->op);
    }

    #[Test]
    public function patches_pin_to_caller_instance_id(): void
    {
        $patches = UiFieldValidationResult::invalid('boom')->toPatches('uci_X');
        foreach ($patches as $p) {
            self::assertSame('uci_X', $p->targetInstance);
        }
    }

    #[Test]
    public function patches_use_only_allow_listed_attributes(): void
    {
        // Defence-in-depth: every setAttribute patch we emit must be
        // on UiResponsePatch::ALLOWED_ATTRIBUTES so the dispatcher's
        // validator never has to reject our own validation patches.
        foreach ([
            UiFieldValidationResult::invalid('x'),
            UiFieldValidationResult::valid('y'),
            UiFieldValidationResult::valid(null),
        ] as $r) {
            foreach ($r->toPatches('uci_X') as $p) {
                if ($p->op === UiResponsePatch::OP_SET_ATTRIBUTE) {
                    self::assertContains(
                        $p->attribute,
                        UiResponsePatch::ALLOWED_ATTRIBUTES,
                        "Validation patch uses an off-list attribute: {$p->attribute}",
                    );
                }
            }
        }
    }
}
