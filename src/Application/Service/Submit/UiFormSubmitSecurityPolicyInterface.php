<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit;

use Semitexa\PlatformUi\Domain\Exception\UiFormSubmitSecurityPolicyException;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitSecurityContext;

/**
 * Submit-side security / CSRF / session policy seam.
 *
 * The policy runs AFTER:
 *   - SignedContext::verify();
 *   - dispatchId replay-guard;
 *   - dispatcher-level UiInteractionAuthorizerInterface;
 *   - authoritative field validation;
 *   - action resolution;
 *   - UiFormSubmitActionAuthorizerInterface.
 *
 * And BEFORE:
 *   - the action's `handle()` method;
 *   - any action-contributed extra patches are validated.
 *
 * This seam exists so that when persistence lands, apps can plug
 * in a real CSRF token check or session-bound policy WITHOUT touching
 * FormComponent. The default
 * {@see SignedContextOnlyUiFormSubmitSecurityPolicy} is deliberately
 * a no-op: the existing signed-ctx + replay-guard pair is enough for
 * the playground demo, but explicitly NOT sufficient for persistent
 * business actions. That guarantee is documented and tested.
 *
 * Contract:
 *   - return normally (void) to pass the policy;
 *   - throw {@see UiFormSubmitSecurityPolicyException} to fail it.
 *     The reason code carried by the exception is a server-owned,
 *     stable token (`csrf_verification_failed`, `session_required`,
 *     `submit_security_failed`). Never include raw values or secrets.
 *
 * Implementations MUST NOT mutate request-scoped state, write logs
 * containing user content, or run persistence-touching services.
 */
interface UiFormSubmitSecurityPolicyInterface
{
    /**
     * @throws UiFormSubmitSecurityPolicyException on policy failure.
     */
    public function verify(UiFormSubmitSecurityContext $context): void;
}
