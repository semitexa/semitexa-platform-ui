<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionResult;
use Semitexa\PlatformUi\Domain\Model\Event\UiResponsePatch;

final class UiInteractionResultTest extends TestCase
{
    #[Test]
    public function ack_factory_produces_kind_ack_with_no_patches(): void
    {
        $r = UiInteractionResult::ack(['value' => 'x']);

        self::assertSame(UiInteractionResult::KIND_ACK, $r->kind);
        self::assertSame(['value' => 'x'], $r->debug);
        self::assertSame([], $r->patches);
    }

    #[Test]
    public function ack_factory_defaults_debug_to_empty(): void
    {
        $r = UiInteractionResult::ack();
        self::assertSame(UiInteractionResult::KIND_ACK, $r->kind);
        self::assertSame([], $r->debug);
    }

    #[Test]
    public function patch_factory_with_non_empty_patches_produces_kind_patch(): void
    {
        $patch = new UiResponsePatch(
            op: UiResponsePatch::OP_SET_TEXT,
            targetInstance: 'uci_test_0001',
            targetPart: null,
            targetName: 'server-ack',
            value: 'Hello',
        );

        $r = UiInteractionResult::patch([$patch], ['value' => 'Hello']);

        self::assertSame(UiInteractionResult::KIND_PATCH, $r->kind);
        self::assertCount(1, $r->patches);
        self::assertSame($patch, $r->patches[0]);
        self::assertSame(['value' => 'Hello'], $r->debug);
    }

    #[Test]
    public function patch_factory_with_empty_patches_degrades_to_ack(): void
    {
        $r = UiInteractionResult::patch([], ['note' => 'no patches']);

        self::assertSame(UiInteractionResult::KIND_ACK, $r->kind);
        self::assertSame([], $r->patches);
        self::assertSame(['note' => 'no patches'], $r->debug);
    }
}
