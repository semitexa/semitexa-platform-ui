<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

use Semitexa\Core\Attribute\AsPipelineListener;
use Semitexa\Core\Pipeline\AuthCheck;
use Semitexa\Core\Pipeline\PipelineListenerInterface;
use Semitexa\Core\Pipeline\RequestPipelineContext;

/**
 * Clears {@see PlatformUiSseSessionState} at the start of every request.
 *
 * Swoole workers persist between requests; without an explicit reset, a
 * session id minted by request A would survive into request B (different
 * user, potentially different page) and let B unknowingly publish patches
 * to A's subscriber stream. Resetting on the AuthCheck phase guarantees a
 * fresh canvas before any handler runs and any template renders a
 * platform-ui manifest.
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
    }
}
