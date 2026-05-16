<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

/**
 * Per-subscription authorization hook for the Platform UI SSE channel.
 *
 * Mirrors UiInteractionAuthorizerInterface (used by the dispatch
 * handler) on purpose — apps that already plug into the dispatch
 * authorizer's bool-return convention can reuse the same pattern for
 * SSE without re-learning a second contract.
 *
 * The handler invokes `authorize()` AFTER the channel token is
 * verified and BEFORE the connection limit lease is claimed. A `false`
 * return aborts the subscription with HTTP 403
 * `subscription_forbidden`. The stream is never opened, the limiter
 * is never consulted, and no patches are delivered.
 *
 * Implementations MUST NOT inspect headers / cookies / etc. directly:
 * authorize() receives a single typed UiSseSubscriptionContext so the
 * call surface stays stable and the test seam stays cheap. If an app
 * needs additional context for its policy (e.g. tenant resolution),
 * it should derive that context upstream (typically when minting the
 * channel token) and embed it as a claim that arrives via
 * `$context->claims`.
 */
interface UiSseSubscriptionAuthorizerInterface
{
    public function authorize(UiSseSubscriptionContext $context): bool;
}
