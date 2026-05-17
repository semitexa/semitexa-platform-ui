<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Exception;

/**
 * Raised by a UiFormSubmitActionAuthorizerInterface implementation
 * when the verified, validated submit action attempt is denied.
 *
 * FormComponent::onSubmit() catches this and emits a safe response:
 * a form-status setText patch with the message argument plus a
 * ui-state setAttribute patch with `invalid`, AND a debug payload
 * carrying `reason: 'action_forbidden'` + the optional reason code.
 * The reason code is a SHORT, server-owned identifier (`role_required`,
 * `rate_limited`, etc.); the message is the user-facing text. Neither
 * field MUST contain raw submitted values, class FQCNs, or service ids.
 */
final class UiFormSubmitActionAuthorizationException extends \RuntimeException
{
    public function __construct(
        string $message = 'Submit action is not allowed.',
        public readonly string $reasonCode = 'action_forbidden',
    ) {
        parent::__construct($message);
    }
}
