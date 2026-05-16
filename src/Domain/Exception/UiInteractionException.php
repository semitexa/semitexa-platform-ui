<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Exception;

use RuntimeException;
use Throwable;

/**
 * Base exception for the UiInteractionDispatcher / HTTP event endpoint.
 *
 * `$httpStatus` is the HTTP status code the endpoint emits when this
 * exception bubbles. `$reason` is a stable machine-readable identifier
 * surfaced in the JSON error body — safe for clients to branch on.
 *
 * Subclasses must never expose method names, class FQCNs, or stack
 * traces in their `getMessage()`. The endpoint serialises only `reason`
 * + a short safe `message`.
 */
abstract class UiInteractionException extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $reason,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
