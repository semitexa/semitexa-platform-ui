<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Submit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionResult;
use Semitexa\PlatformUi\Domain\Model\Event\UiResponsePatch;

/**
 * Value-object surface for action results. Pins:
 *   - factories emit the documented defaults;
 *   - debug projection omits the empty detail map;
 *   - extra patches default to an empty list;
 *   - the result is readonly (mutating would be a compile error
 *     in PHP 8.2+, so we assert on the typed shape instead).
 */
final class UiFormSubmitActionResultTest extends TestCase
{
    #[Test]
    public function accepted_factory_returns_default_message_and_no_detail(): void
    {
        $r = UiFormSubmitActionResult::accepted();
        self::assertTrue($r->accepted);
        self::assertSame('Form action completed.', $r->message);
        self::assertSame([], $r->debug);
        self::assertSame([], $r->extraPatches);
        self::assertSame(['accepted' => true, 'message' => 'Form action completed.'], $r->toDebug());
    }

    #[Test]
    public function rejected_factory_returns_default_message_and_accepted_false(): void
    {
        $r = UiFormSubmitActionResult::rejected();
        self::assertFalse($r->accepted);
        self::assertSame('Form action rejected.', $r->message);
        self::assertSame(['accepted' => false, 'message' => 'Form action rejected.'], $r->toDebug());
    }

    #[Test]
    public function accepted_factory_carries_optional_debug_detail(): void
    {
        $r = UiFormSubmitActionResult::accepted(
            message: 'Done.',
            debug: ['fieldCount' => 2, 'snapshotFieldCount' => 2],
        );
        self::assertSame(
            ['accepted' => true, 'message' => 'Done.', 'detail' => ['fieldCount' => 2, 'snapshotFieldCount' => 2]],
            $r->toDebug(),
        );
    }

    #[Test]
    public function extra_patches_round_trip_unchanged(): void
    {
        $patch = new UiResponsePatch(
            op: UiResponsePatch::OP_SET_TEXT,
            targetInstance: 'uci_x',
            targetPart: null,
            targetName: 'form-status',
            value: 'extra',
        );
        $r = UiFormSubmitActionResult::accepted(extraPatches: [$patch]);
        self::assertCount(1, $r->extraPatches);
        self::assertSame($patch, $r->extraPatches[0]);
    }
}
