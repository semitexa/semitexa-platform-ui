<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit;

use Semitexa\Core\Attribute\SatisfiesServiceContract;

/**
 * Default UiDemoSubmissionAdminAuthorizerInterface implementation —
 * allows every diagnostic listing request that reaches it.
 *
 * Default binding: registered via `#[SatisfiesServiceContract]` so
 * the dev-facing UiPlayground module's `/ui-playground/admin/demo-submissions`
 * page works without caller-side wiring. `authorize()` is still
 * invoked on every request so a custom authorizer can replace the
 * default without touching the handler.
 *
 * **Production warning** (also printed on the diagnostic page):
 * apps that mount UiPlayground in any non-dev environment MUST bind
 * their own authorizer. This default allows everyone — appropriate
 * for the playground, not for production.
 */
#[SatisfiesServiceContract(of: UiDemoSubmissionAdminAuthorizerInterface::class)]
final class AllowAllUiDemoSubmissionAdminAuthorizer implements UiDemoSubmissionAdminAuthorizerInterface
{
    public function authorize(): void
    {
        // Demo-default: allow everything. Override by binding a
        // custom authorizer to UiDemoSubmissionAdminAuthorizerInterface.
    }
}
