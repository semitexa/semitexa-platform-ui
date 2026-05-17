<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Exception;

/**
 * Raised by a UiDemoSubmissionAdminAuthorizerInterface implementation
 * when the diagnostic listing route should not be served to the
 * current caller.
 *
 * The route handler catches this and renders the diagnostic template
 * with a safe denial state (HTTP 403, no submission rows). The
 * `reasonCode` is a short, server-owned token; the `message` is the
 * user-facing copy. Neither field MUST contain raw values, class
 * FQCNs, service ids, or session details.
 */
final class UiDemoSubmissionAdminAuthorizationException extends \RuntimeException
{
    public function __construct(
        string $message = 'Diagnostic listing access is denied.',
        public readonly string $reasonCode = 'demo_admin_forbidden',
    ) {
        parent::__construct($message);
    }
}
