<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Event\PlatformUiAuthState;
use Semitexa\PlatformUi\Application\Service\Event\PlatformUiSseSessionState;
use Swoole\Coroutine;

/**
 * The load-bearing fix: a Swoole worker serves many requests concurrently on
 * separate coroutines. When the SSE session id and the auth state lived in a
 * process-static, two concurrent requests SHARED it — a request could publish
 * `ui.patch` frames to another user's live stream, and a guest could observe a
 * concurrent authenticated request's `true` and get the LIVE transport. Backed
 * by the coroutine context, each request's state is isolated.
 *
 * Each test interleaves two coroutines via staggered sleeps so one writes its
 * value BETWEEN the other's write and read, and proves neither leaks.
 */
final class PlatformUiStateCoroutineIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }
    }

    #[Test]
    public function concurrent_coroutines_get_isolated_sse_session_ids(): void
    {
        $seen = [];
        Coroutine\run(function () use (&$seen): void {
            // A sets its id, yields; B wakes in the gap and sets a DIFFERENT id;
            // A wakes and must still read ITS OWN id, not B's.
            Coroutine::create(function () use (&$seen): void {
                PlatformUiSseSessionState::setForTesting('sse_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
                Coroutine::sleep(0.03);
                $seen['a'] = PlatformUiSseSessionState::current();
            });
            Coroutine::create(function () use (&$seen): void {
                Coroutine::sleep(0.01); // start after A's set
                PlatformUiSseSessionState::setForTesting('sse_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
                $seen['b'] = PlatformUiSseSessionState::current();
            });
        });

        self::assertSame('sse_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $seen['a'], 'A must keep its own id');
        self::assertSame('sse_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $seen['b'], 'B must keep its own id');
    }

    #[Test]
    public function a_guest_coroutine_never_observes_a_concurrent_authenticated_state(): void
    {
        $seen = [];
        Coroutine\run(function () use (&$seen): void {
            Coroutine::create(function () use (&$seen): void {
                PlatformUiAuthState::set(true);       // authenticated request
                Coroutine::sleep(0.03);
                $seen['auth'] = PlatformUiAuthState::current();
            });
            Coroutine::create(function () use (&$seen): void {
                Coroutine::sleep(0.01);               // concurrent guest request
                $seen['guest'] = PlatformUiAuthState::current(); // never set → null
            });
        });

        self::assertTrue($seen['auth'], 'the authenticated coroutine keeps true');
        self::assertNull($seen['guest'], 'the guest coroutine must NOT inherit the concurrent true');
    }
}
