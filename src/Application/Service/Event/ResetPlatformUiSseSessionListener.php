<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

use Semitexa\Core\Attribute\AsPipelineListener;
use Semitexa\Core\Pipeline\AuthCheck;
use Semitexa\Core\Pipeline\PipelineListenerInterface;
use Semitexa\Core\Pipeline\RequestPipelineContext;

/**
 * Clears platform-ui per-request render holders at the start of every
 * request: {@see PlatformUiSseSessionState} (the canonical SSE
 * subscriber channel id) and {@see PlatformUiAuthState} (the
 * auth-derived transport-mode hint).
 *
 * Swoole workers persist between requests; without an explicit reset, a
 * session id minted by request A would survive into request B (different
 * user, potentially different page) and let B unknowingly publish patches
 * to A's subscriber stream — and, equally, A's authenticated state would
 * leak into a later guest request B and silently upgrade it to a live
 * stream. Resetting on the AuthCheck phase guarantees a fresh canvas
 * before any handler runs and any template renders a platform-ui
 * manifest. The auth holder is reset to its "unknown" (null) state, so a
 * request whose app bridge does not run falls back to the drain default.
 *
 * Why AuthCheck (not WorkerStart, not a TenantResolved listener):
 *
 *   - WorkerStart fires once per worker, not per request — wrong scope.
 *   - TenantResolved fires during TenancyPhase before SessionPhase, so
 *     Request injection is not yet available; we don't actually need
 *     Request here, but the same lesson applies — AuthCheck is the
 *     documented canonical join point for request-scoped state (see
 *     ApplyThemeOnAuthCheckListener in semitexa/theme for the prior
 *     art and the rationale).
 *   - Running at the *lowest* priority (-1000) means the reset happens
 *     before any other AuthCheck listener has a chance to read or write
 *     session state. Priority is ascending — lower runs first.
 */
#[AsPipelineListener(phase: AuthCheck::class, priority: -1000)]
final class ResetPlatformUiSseSessionListener implements PipelineListenerInterface
{
    public function handle(RequestPipelineContext $context): void
    {
        PlatformUiSseSessionState::reset();
        PlatformUiAuthState::reset();
    }
}
