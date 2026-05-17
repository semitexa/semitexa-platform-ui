<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Exception;

/**
 * Raised by a UiFormSubmitSecurityPolicyInterface implementation
 * when the submit's security / CSRF / session policy check fails.
 *
 * The policy seam exists so apps can plug in real CSRF or session
 * verification before persistence lands. Default
 * SignedContextOnlyUiFormSubmitSecurityPolicy never throws — it
 * trusts the existing signed-ctx + replay guard. Custom policies
 * MUST throw this exception with a stable reason code so
 * FormComponent::onSubmit() can project safe patches uniformly.
 *
 * Reason code conventions:
 *   - `csrf_verification_failed`   — bound CSRF token missing/invalid;
 *   - `session_required`           — caller has no usable session;
 *   - `submit_security_failed`     — generic fallback.
 */
final class UiFormSubmitSecurityPolicyException extends \RuntimeException
{
    public function __construct(
        string $message = 'Submit security policy denied this submission.',
        public readonly string $reasonCode = 'submit_security_failed',
    ) {
        parent::__construct($message);
    }
}
