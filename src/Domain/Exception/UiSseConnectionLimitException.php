<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Exception;

/**
 * SSE subscription was refused because a connection limit
 * (per-IP or global) is currently saturated.
 *
 * HTTP 429 Too Many Requests so well-behaved clients back off.
 * The reason token / message MUST stay safe — no internal counters,
 * no class FQCNs, no secrets — so it can be surfaced verbatim in the
 * response body.
 */
final class UiSseConnectionLimitException extends UiInteractionException
{
    public function __construct(string $reason, string $message)
    {
        parent::__construct(429, $reason, $message);
    }
}
