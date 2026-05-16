<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Exception;

/**
 * Raised when a developer-authored rule spec is malformed
 * (unknown rule name, wrong parameter count, non-scalar parameter,
 * unsafe shape). Surfaced at template render time so misconfigurations
 * fail loudly in dev.
 *
 * If a malformed spec reaches the dispatcher at runtime (e.g. a
 * tampered signed ctx that somehow passes HMAC), the dispatcher maps
 * this to a 422 `invalid_validation_rule` JSON response without
 * leaking class FQCNs or stack traces.
 */
final class UiFieldValidationRuleException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $ruleName = '')
    {
        parent::__construct($message);
    }
}
