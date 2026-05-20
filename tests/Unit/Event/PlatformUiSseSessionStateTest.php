<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Event;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Event\PlatformUiSseSessionState;

final class PlatformUiSseSessionStateTest extends TestCase
{
    protected function setUp(): void
    {
        PlatformUiSseSessionState::reset();
    }

    protected function tearDown(): void
    {
        PlatformUiSseSessionState::reset();
    }

    #[Test]
    public function current_is_null_before_first_mint(): void
    {
        self::assertNull(PlatformUiSseSessionState::current());
    }

    #[Test]
    public function mint_if_absent_returns_safe_shape(): void
    {
        $id = PlatformUiSseSessionState::mintIfAbsent();

        self::assertMatchesRegularExpression(PlatformUiSseSessionState::SAFE_ID_PATTERN, $id);
        self::assertStringStartsWith('sse_', $id);
        self::assertSame($id, PlatformUiSseSessionState::current());
    }

    #[Test]
    public function mint_if_absent_is_idempotent_within_one_request(): void
    {
        $first = PlatformUiSseSessionState::mintIfAbsent();
        $second = PlatformUiSseSessionState::mintIfAbsent();

        self::assertSame($first, $second);
    }

    #[Test]
    public function reset_clears_the_holder(): void
    {
        PlatformUiSseSessionState::mintIfAbsent();
        PlatformUiSseSessionState::reset();

        self::assertNull(PlatformUiSseSessionState::current());
    }

    #[Test]
    public function mint_after_reset_returns_a_different_id(): void
    {
        $first = PlatformUiSseSessionState::mintIfAbsent();
        PlatformUiSseSessionState::reset();
        $second = PlatformUiSseSessionState::mintIfAbsent();

        self::assertNotSame($first, $second);
    }

    #[Test]
    public function set_for_testing_accepts_safe_id(): void
    {
        PlatformUiSseSessionState::setForTesting('sse_deterministic_001');

        self::assertSame('sse_deterministic_001', PlatformUiSseSessionState::current());
    }

    #[Test]
    public function set_for_testing_rejects_unsafe_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PlatformUiSseSessionState::setForTesting('has space');
    }

    #[Test]
    public function set_for_testing_rejects_id_with_leading_dash(): void
    {
        // SAFE_ID_PATTERN requires alphanumeric leading char — keeps
        // the id safe to use as a CLI / log token without quoting.
        $this->expectException(InvalidArgumentException::class);
        PlatformUiSseSessionState::setForTesting('-foo');
    }
}
