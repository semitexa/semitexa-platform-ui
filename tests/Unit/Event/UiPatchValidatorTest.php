<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Event\UiPatchValidator;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionUnprocessableException;
use Semitexa\PlatformUi\Domain\Model\Event\UiResponsePatch;

final class UiPatchValidatorTest extends TestCase
{
    private const INSTANCE = 'uci_validator_001';

    private UiPatchValidator $v;

    protected function setUp(): void
    {
        $this->v = new UiPatchValidator();
    }

    private function patch(
        string $op = UiResponsePatch::OP_SET_TEXT,
        string $instance = self::INSTANCE,
        ?string $part = null,
        ?string $name = 'server-ack',
        mixed $value = 'hello',
        ?string $attribute = null,
    ): UiResponsePatch {
        return new UiResponsePatch(
            op: $op,
            targetInstance: $instance,
            targetPart: $part,
            targetName: $name,
            value: $value,
            attribute: $attribute,
        );
    }

    #[Test]
    public function valid_set_text_patch_passes(): void
    {
        $out = $this->v->validateAll([$this->patch()], self::INSTANCE);
        self::assertCount(1, $out);
    }

    #[Test]
    public function valid_set_value_patch_passes(): void
    {
        $out = $this->v->validateAll(
            [$this->patch(op: UiResponsePatch::OP_SET_VALUE, name: null, part: 'input', value: 'echo')],
            self::INSTANCE,
        );
        self::assertCount(1, $out);
    }

    #[Test]
    public function valid_set_attribute_with_allowed_name_passes(): void
    {
        $out = $this->v->validateAll(
            [$this->patch(
                op: UiResponsePatch::OP_SET_ATTRIBUTE,
                part: 'input',
                name: null,
                value: 'true',
                attribute: 'aria-invalid',
            )],
            self::INSTANCE,
        );
        self::assertCount(1, $out);
    }

    #[Test]
    public function unknown_op_is_rejected(): void
    {
        $this->expectException(UiInteractionUnprocessableException::class);
        $this->expectExceptionMessageMatches('/unsupported op/');
        $this->v->validateAll([$this->patch(op: 'setHtml')], self::INSTANCE);
    }

    #[Test]
    public function instance_mismatch_is_rejected(): void
    {
        $this->expectException(UiInteractionUnprocessableException::class);
        $this->expectExceptionMessageMatches('/different component instance/');
        $this->v->validateAll([$this->patch(instance: 'uci_other_evil')], self::INSTANCE);
    }

    #[Test]
    public function invalid_part_name_is_rejected(): void
    {
        $this->expectException(UiInteractionUnprocessableException::class);
        $this->expectExceptionMessageMatches('/invalid part name/');
        $this->v->validateAll(
            [$this->patch(part: '..//evil')],
            self::INSTANCE,
        );
    }

    #[Test]
    public function invalid_target_name_is_rejected(): void
    {
        $this->expectException(UiInteractionUnprocessableException::class);
        $this->expectExceptionMessageMatches('/invalid patch-target name/');
        $this->v->validateAll(
            [$this->patch(name: 'a b c')],
            self::INSTANCE,
        );
    }

    #[Test]
    public function non_scalar_value_for_set_text_is_rejected(): void
    {
        $this->expectException(UiInteractionUnprocessableException::class);
        $this->expectExceptionMessageMatches('/requires a scalar/');
        $this->v->validateAll(
            [$this->patch(value: ['nested' => 'bad'])],
            self::INSTANCE,
        );
    }

    #[Test]
    public function non_scalar_value_for_set_value_is_rejected(): void
    {
        $this->expectException(UiInteractionUnprocessableException::class);
        $this->expectExceptionMessageMatches('/requires a scalar/');
        $this->v->validateAll(
            [$this->patch(op: UiResponsePatch::OP_SET_VALUE, value: new \stdClass())],
            self::INSTANCE,
        );
    }

    #[Test]
    public function set_attribute_with_disallowed_attribute_is_rejected(): void
    {
        $this->expectException(UiInteractionUnprocessableException::class);
        $this->expectExceptionMessageMatches('/allow-listed attribute/');
        $this->v->validateAll(
            [$this->patch(
                op: UiResponsePatch::OP_SET_ATTRIBUTE,
                part: 'input',
                name: null,
                value: 'evil()',
                attribute: 'onclick',
            )],
            self::INSTANCE,
        );
    }

    #[Test]
    public function set_attribute_without_attribute_name_is_rejected(): void
    {
        $this->expectException(UiInteractionUnprocessableException::class);
        $this->expectExceptionMessageMatches('/allow-listed attribute/');
        $this->v->validateAll(
            [$this->patch(op: UiResponsePatch::OP_SET_ATTRIBUTE, attribute: null)],
            self::INSTANCE,
        );
    }

    #[Test]
    public function non_patch_in_list_is_rejected(): void
    {
        $this->expectException(UiInteractionUnprocessableException::class);
        $this->expectExceptionMessageMatches('/non-UiResponsePatch/');
        $this->v->validateAll(['not-a-patch'], self::INSTANCE);
    }

    #[Test]
    public function null_value_is_allowed_for_set_text(): void
    {
        $out = $this->v->validateAll(
            [$this->patch(value: null)],
            self::INSTANCE,
        );
        self::assertCount(1, $out);
    }

    #[Test]
    public function empty_patch_list_is_a_no_op(): void
    {
        $out = $this->v->validateAll([], self::INSTANCE);
        self::assertSame([], $out);
    }

    // ---------------------------------------------------------------
    // Additional-allowed-instances allow-list (per-field submit
    // projection — patches targeting cfg.f.i field instance ids).
    // ---------------------------------------------------------------

    #[Test]
    public function patch_targeting_additional_allowed_instance_is_accepted(): void
    {
        $other = 'uci_other_signed_instance';
        $patch = $this->patch(instance: $other, value: 'looks good');
        $out = $this->v->validateAll([$patch], self::INSTANCE, [$other]);
        self::assertCount(1, $out);
    }

    #[Test]
    public function patch_targeting_instance_not_in_allow_list_is_rejected(): void
    {
        $this->expectException(\Semitexa\PlatformUi\Domain\Exception\UiInteractionUnprocessableException::class);
        $this->expectExceptionMessage('different component instance');
        $patch = $this->patch(instance: 'uci_evil_target', value: 'x');
        $this->v->validateAll([$patch], self::INSTANCE, ['uci_friend']);
    }

    #[Test]
    public function additional_allow_list_does_not_replace_primary_instance(): void
    {
        // The primary $expectedInstance must remain accepted even
        // when the additional list is non-empty.
        $patch = $this->patch(instance: self::INSTANCE, value: 'still ok');
        $out = $this->v->validateAll([$patch], self::INSTANCE, ['uci_extra']);
        self::assertCount(1, $out);
    }

    #[Test]
    public function empty_string_or_non_string_additional_entries_are_ignored(): void
    {
        // The validator silently drops bad entries; callers can
        // hand it noisy lists from the signed-claims walk without a
        // separate sanitiser step. Bad entries do NOT widen the
        // allow-list to include them.
        $this->expectException(\Semitexa\PlatformUi\Domain\Exception\UiInteractionUnprocessableException::class);
        /** @psalm-suppress InvalidArgument */
        $this->v->validateAll(
            [$this->patch(instance: 'uci_real_target', value: 'x')],
            self::INSTANCE,
            ['', null, 0, 'uci_unrelated'],
        );
    }
}
