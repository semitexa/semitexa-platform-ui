<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitResult;
use Semitexa\PlatformUi\Domain\Model\Event\UiResponsePatch;

final class UiFormSubmitResultTest extends TestCase
{
    private function field(string $name, string $state, ?string $message): array
    {
        return ['name' => $name, 'state' => $state, 'message' => $message];
    }

    #[Test]
    public function all_valid_yields_accepted_message_and_valid_state(): void
    {
        $r = UiFormSubmitResult::fromFieldResults([
            $this->field('a', 'valid', 'Looks good.'),
            $this->field('b', 'valid', 'Looks good.'),
        ]);
        self::assertTrue($r->valid);
        self::assertSame(2, $r->totalCount);
        self::assertSame(2, $r->validCount);
        self::assertSame(0, $r->invalidCount);
        self::assertSame('Form is valid. Submit accepted.', $r->message);
    }

    #[Test]
    public function single_invalid_message(): void
    {
        $r = UiFormSubmitResult::fromFieldResults([
            $this->field('a', 'valid', null),
            $this->field('b', 'invalid', 'Bad.'),
        ]);
        self::assertFalse($r->valid);
        self::assertSame(2, $r->totalCount);
        self::assertSame(1, $r->validCount);
        self::assertSame(1, $r->invalidCount);
        self::assertSame('1 field needs attention.', $r->message);
    }

    #[Test]
    public function plural_invalid_message(): void
    {
        $r = UiFormSubmitResult::fromFieldResults([
            $this->field('a', 'invalid', 'Bad.'),
            $this->field('b', 'invalid', 'Bad.'),
            $this->field('c', 'valid',   null),
        ]);
        self::assertFalse($r->valid);
        self::assertSame(2, $r->invalidCount);
        self::assertSame('2 fields need attention.', $r->message);
    }

    #[Test]
    public function empty_config_has_no_fields_message(): void
    {
        $r = UiFormSubmitResult::fromFieldResults([]);
        self::assertFalse($r->valid);
        self::assertSame(0, $r->totalCount);
        self::assertSame('Form has no fields.', $r->message);
    }

    #[Test]
    public function patches_target_form_status_and_form_root_ui_state(): void
    {
        $r = UiFormSubmitResult::fromFieldResults([
            $this->field('a', 'valid', 'Looks good.'),
        ]);
        $patches = $r->toPatches('uci_form_01');
        self::assertCount(2, $patches);

        /** @var UiResponsePatch $textPatch */
        $textPatch = $patches[0];
        self::assertSame(UiResponsePatch::OP_SET_TEXT, $textPatch->op);
        self::assertSame('uci_form_01', $textPatch->targetInstance);
        self::assertNull($textPatch->targetPart);
        self::assertSame('form-status', $textPatch->targetName);
        self::assertSame('Form is valid. Submit accepted.', $textPatch->value);

        /** @var UiResponsePatch $attrPatch */
        $attrPatch = $patches[1];
        self::assertSame(UiResponsePatch::OP_SET_ATTRIBUTE, $attrPatch->op);
        self::assertSame('uci_form_01', $attrPatch->targetInstance);
        self::assertNull($attrPatch->targetPart);
        self::assertNull($attrPatch->targetName);
        self::assertSame('ui-state', $attrPatch->attribute);
        self::assertSame('valid', $attrPatch->value);
    }

    #[Test]
    public function invalid_result_emits_ui_state_invalid(): void
    {
        $r = UiFormSubmitResult::fromFieldResults([
            $this->field('a', 'invalid', 'Bad.'),
        ]);
        $patches = $r->toPatches('uci_form_01');
        self::assertSame('invalid', $patches[1]->value);
        self::assertSame('1 field needs attention.', $patches[0]->value);
    }

    #[Test]
    public function debug_carries_counts_and_per_field_state_no_values(): void
    {
        $r = UiFormSubmitResult::fromFieldResults([
            $this->field('a', 'valid',   'Looks good.'),
            $this->field('b', 'invalid', 'Bad.'),
        ]);
        $debug = $r->toDebug();
        self::assertSame([
            'valid'        => false,
            'totalCount'   => 2,
            'validCount'   => 1,
            'invalidCount' => 1,
            'fields' => [
                ['name' => 'a', 'state' => 'valid',   'message' => 'Looks good.'],
                ['name' => 'b', 'state' => 'invalid', 'message' => 'Bad.'],
            ],
            'message' => '1 field needs attention.',
        ], $debug);
        // No "value" key anywhere — submitted values must NOT
        // surface in the debug projection.
        foreach ($debug['fields'] as $f) {
            self::assertArrayNotHasKey('value', $f);
        }
    }
}
