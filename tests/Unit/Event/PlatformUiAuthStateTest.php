<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Event\PlatformUiAuthState;

/**
 * Per-request auth-state holder semantics. The holder is a static,
 * per-process slot reset on every request (see
 * ResetPlatformUiSseSessionListener); these tests reset it explicitly
 * around each case so the static state never bleeds between tests.
 */
final class PlatformUiAuthStateTest extends TestCase
{
    protected function setUp(): void
    {
        PlatformUiAuthState::reset();
    }

    protected function tearDown(): void
    {
        PlatformUiAuthState::reset();
    }

    #[Test]
    public function default_state_is_unknown_null(): void
    {
        // No bridge has run → unknown. The policy treats this as drain,
        // preserving pre-feature behaviour for apps that never wire the
        // AuthCheck bridge.
        self::assertNull(PlatformUiAuthState::current());
    }

    #[Test]
    public function set_true_records_authenticated(): void
    {
        PlatformUiAuthState::set(true);
        self::assertTrue(PlatformUiAuthState::current());
    }

    #[Test]
    public function set_false_records_guest(): void
    {
        PlatformUiAuthState::set(false);
        self::assertFalse(PlatformUiAuthState::current());
    }

    #[Test]
    public function last_write_within_request_wins(): void
    {
        PlatformUiAuthState::set(true);
        PlatformUiAuthState::set(false);
        self::assertFalse(PlatformUiAuthState::current());
    }

    #[Test]
    public function reset_clears_back_to_unknown(): void
    {
        PlatformUiAuthState::set(true);
        PlatformUiAuthState::reset();
        self::assertNull(PlatformUiAuthState::current());
    }
}
