<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit;

use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionResult;

/**
 * Server-side action invoked by FormComponent::onSubmit AFTER
 * authoritative validation succeeds.
 *
 * The action seam exists to give applications a typed extension point
 * for "form is valid — now do the thing": surface a confirmation
 * message, run a domain-side service, return extra patches. It is NOT
 * a persistence hook in this slice — the demo built-in
 * (`platform.demo.accept`) returns an accepted message and does
 * nothing else. Adding persistence requires a separate slice with
 * authorization, CSRF/session policy, and storage-specific input
 * validation.
 *
 * Security perimeter:
 *
 *   - The action NAME is signed into the form's submit ctx (`cfg.a`).
 *     Tampering breaks the HMAC; the client cannot inject an action
 *     through the request payload (UiPayloadFieldGuard rejects
 *     `payload.action` / `payload.submitAction`).
 *   - The registry resolves the action name through a fixed `match`
 *     expression — never via `new $name(...)` or `class_exists($name)`.
 *     Custom registries MUST follow the same pattern.
 *   - The action receives only sanitised values + server-owned field
 *     definitions + validation summary. It does NOT receive the raw
 *     SignedContext blob, the Request object, or container services.
 *   - The action's return is a typed UiFormSubmitActionResult; extra
 *     patches it contributes are validated through UiPatchValidator
 *     the same way every other dispatch patch is.
 *
 * Implementations MUST:
 *
 *   - Treat $context->values as untrusted input.
 *   - Stay idempotent: the dispatcher's replay protection guarantees a
 *     given (ctx, dispatchId) pair is processed once, but a client may
 *     retry with a fresh dispatchId.
 *   - Never throw uncaught exceptions for expected business outcomes;
 *     return `UiFormSubmitActionResult::rejected(...)` instead.
 *   - Never echo raw submitted values back in `message` or `debug`.
 */
interface UiFormSubmitActionInterface
{
    /**
     * Stable, server-owned name (e.g. `platform.demo.accept`). Used by
     * the registry to resolve this action and signed verbatim into
     * `cfg.a` at render time.
     */
    public function name(): string;

    /**
     * Invoke the action against the verified submit context.
     */
    public function handle(UiFormSubmitActionContext $context): UiFormSubmitActionResult;
}
