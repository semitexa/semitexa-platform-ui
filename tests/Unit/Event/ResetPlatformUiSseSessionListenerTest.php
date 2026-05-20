<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Semitexa\Core\Attribute\AsPipelineListener;
use Semitexa\Core\Pipeline\AuthCheck;
use Semitexa\PlatformUi\Application\Service\Event\PlatformUiSseSessionState;
use Semitexa\PlatformUi\Application\Service\Event\ResetPlatformUiSseSessionListener;

final class ResetPlatformUiSseSessionListenerTest extends TestCase
{
    protected function tearDown(): void
    {
        PlatformUiSseSessionState::reset();
    }

    #[Test]
    public function listener_is_attributed_to_auth_check_phase(): void
    {
        $rc = new ReflectionClass(ResetPlatformUiSseSessionListener::class);
        $attrs = $rc->getAttributes(AsPipelineListener::class);

        self::assertCount(1, $attrs);
        /** @var AsPipelineListener $instance */
        $instance = $attrs[0]->newInstance();
        // AuthCheck is the canonical request-scoped integration point
        // — the prior art (ApplyThemeOnAuthCheckListener) documents
        // why. A change here would silently delay or skip the reset
        // and let session-id state leak between requests.
        self::assertSame(AuthCheck::class, $instance->phase);
        // Low priority so the reset runs FIRST in the phase, before
        // any AuthCheck listener can read or write SSE state.
        self::assertLessThanOrEqual(-1000, $instance->priority);
    }

    #[Test]
    public function handle_clears_state_when_state_was_set(): void
    {
        PlatformUiSseSessionState::setForTesting('sse_pre_request_001');
        self::assertSame('sse_pre_request_001', PlatformUiSseSessionState::current());

        // Pass a minimal stub context — the listener does not read
        // any field from the context, so a stdClass shim is enough
        // for the unit test surface. (The Swoole runtime supplies a
        // fully-populated RequestPipelineContext at production time.)
        $listener = new ResetPlatformUiSseSessionListener();
        $listener->handle($this->dummyContext());

        self::assertNull(PlatformUiSseSessionState::current());
    }

    #[Test]
    public function handle_is_safe_when_state_is_already_clear(): void
    {
        PlatformUiSseSessionState::reset();
        self::assertNull(PlatformUiSseSessionState::current());

        $listener = new ResetPlatformUiSseSessionListener();
        $listener->handle($this->dummyContext());

        self::assertNull(PlatformUiSseSessionState::current());
    }

    /**
     * Builds the smallest RequestPipelineContext-compatible stub the
     * listener will accept. The listener never touches the context's
     * fields, so we cast a generic anonymous object — full pipeline
     * coverage lives in the framework's own pipeline tests.
     */
    private function dummyContext(): \Semitexa\Core\Pipeline\RequestPipelineContext
    {
        // RequestPipelineContext requires concrete classes for its
        // constructor (Request, DiscoveredRoute, etc.). For this unit
        // test we only need the listener's `handle()` signature to
        // accept the call — we instantiate via reflection so we don't
        // depend on the framework's request-construction internals
        // (which would re-pull half the SSR stack into this unit test).
        $rc = new ReflectionClass(\Semitexa\Core\Pipeline\RequestPipelineContext::class);
        return $rc->newInstanceWithoutConstructor();
    }
}
