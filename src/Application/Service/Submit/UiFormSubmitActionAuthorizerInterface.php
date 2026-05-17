<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit;

use Semitexa\PlatformUi\Domain\Exception\UiFormSubmitActionAuthorizationException;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionAuthorizationContext;

/**
 * Policy seam for FormComponent submit actions.
 *
 * Runs AFTER:
 *   - SignedContext::verify() passed;
 *   - dispatchId replay-guard succeeded;
 *   - dispatcher-level UiInteractionAuthorizerInterface allowed the
 *     event onto the component;
 *   - authoritative field validation passed;
 *   - the action was resolved from
 *     UiFormSubmitActionRegistryInterface.
 *
 * Runs BEFORE:
 *   - UiFormSubmitSecurityPolicyInterface;
 *   - the action's `handle()` method;
 *   - any action-contributed extra patches are validated.
 *
 * Contract:
 *   - return normally (void) to allow the action invocation;
 *   - throw {@see UiFormSubmitActionAuthorizationException} to deny.
 *     The reason code carried by the exception MUST be a short,
 *     server-owned token (e.g. `role_required`, `rate_limited`).
 *     The message is the user-facing text — never include raw
 *     submitted values, class FQCNs, or service ids.
 *
 * The default {@see AllowAllUiFormSubmitActionAuthorizer} keeps the
 * existing playground / demo behaviour. Apps bind their own via
 * `#[SatisfiesServiceContract(of: UiFormSubmitActionAuthorizerInterface::class)]`
 * inside a module that "extends" semitexa-platform-ui.
 *
 * The exception/void style differs from the dispatcher-level
 * UiInteractionAuthorizerInterface (which returns bool and converts
 * to a generic 403). Submit actions need a richer denial channel so
 * the form-status message and `debug.action.reason` carry stable
 * tokens, so we lean on a typed exception here.
 *
 * Implementations MUST NOT mutate request-scoped state, write logs
 * containing user content, or call services that touch persistence.
 */
interface UiFormSubmitActionAuthorizerInterface
{
    /**
     * @throws UiFormSubmitActionAuthorizationException on deny.
     */
    public function authorize(UiFormSubmitActionAuthorizationContext $context): void;
}
