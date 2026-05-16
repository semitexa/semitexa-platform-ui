<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Exception;

/**
 * Server-side configuration is unsafe for production dispatch.
 *
 * Currently raised by the dispatcher's runtime guard when it detects
 * the bound UiReplayStoreInterface is NOT shared across workers while
 * APP_ENV is a production-like value. HTTP 503 because the service
 * cannot safely serve dispatch requests until an operator fixes the
 * cache configuration (typically by setting CACHE_DRIVER=redis).
 *
 * The reason token / message MUST stay safe — no class FQCNs, no
 * connection strings, no file paths — so it can be surfaced verbatim
 * in the JSON response.
 */
final class UiInteractionConfigurationException extends UiInteractionException
{
    public function __construct(string $reason, string $message)
    {
        parent::__construct(503, $reason, $message);
    }
}
