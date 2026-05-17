<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit;

/**
 * Explicit dev-only UiDemoSubmissionAdminAuthorizerInterface implementation —
 * allows every diagnostic listing request that reaches it.
 *
 * NOT marked `#[SatisfiesServiceContract]`: the package default is
 * deny-by-default via ConfigurableUiDemoSubmissionAdminAuthorizer.
 * Tests and dev-only playground wiring may still install this class
 * explicitly when open diagnostics are intended.
 */
final class AllowAllUiDemoSubmissionAdminAuthorizer implements UiDemoSubmissionAdminAuthorizerInterface
{
    public function authorize(): void
    {
        // Demo-default: allow everything. Override by binding a
        // custom authorizer to UiDemoSubmissionAdminAuthorizerInterface.
    }
}
