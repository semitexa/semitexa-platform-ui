<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionAuthorizationContext;

/**
 * Default UiFormSubmitActionAuthorizerInterface implementation —
 * allows every submit action attempt that reaches it.
 *
 * Default binding: registered via `#[SatisfiesServiceContract]` so
 * existing playground / demo behaviour (the `platform.demo.accept`
 * action) keeps working without any caller-side wiring. The
 * authorize() method intentionally still runs through this seam on
 * every valid submit so authzhooks (logging, audit, etc.) can be
 * added in custom implementations without touching FormComponent.
 *
 * Apps override by declaring their own implementation with
 * `#[SatisfiesServiceContract(of: UiFormSubmitActionAuthorizerInterface::class)]`
 * in a module that "extends" semitexa-platform-ui. The contract
 * registry picks the descendant-module winner; the
 * BootPlatformUiRegistryListener then stashes that winner in
 * UiFormSubmitActionAuthorizer so the FormComponent submit handler
 * can read it without container access.
 *
 * The default impl has no side effects — never mutates state, never
 * writes logs, never resolves additional services. Safe to instantiate
 * in tests directly without any setup.
 */
#[SatisfiesServiceContract(of: UiFormSubmitActionAuthorizerInterface::class)]
final class AllowAllUiFormSubmitActionAuthorizer implements UiFormSubmitActionAuthorizerInterface
{
    public function authorize(UiFormSubmitActionAuthorizationContext $context): void
    {
        // Allow everything by default. Override by binding a custom
        // authorizer to UiFormSubmitActionAuthorizerInterface and
        // throwing UiFormSubmitActionAuthorizationException on deny.
    }
}
