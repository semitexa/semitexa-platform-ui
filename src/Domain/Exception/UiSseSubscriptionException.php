<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Exception;

/**
 * SSE subscription was rejected — typically because the channel token
 * is missing, malformed, expired, or carries the wrong purpose claim.
 *
 * Defaults to HTTP 401 because subscribing without a valid token is an
 * unauthenticated/unauthorized request, NOT a malformed-input bug. The
 * reason token / message MUST stay safe — no FQCNs, no secrets — so it
 * can be surfaced verbatim in the response body or SSE error event.
 */
final class UiSseSubscriptionException extends UiInteractionException
{
    public function __construct(string $reason, string $message, int $httpStatus = 401)
    {
        parent::__construct($httpStatus, $reason, $message);
    }
}
