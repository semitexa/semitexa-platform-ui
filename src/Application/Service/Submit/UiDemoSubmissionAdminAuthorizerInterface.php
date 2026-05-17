<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit;

use Semitexa\PlatformUi\Domain\Exception\UiDemoSubmissionAdminAuthorizationException;

/**
 * Policy seam for the read-only diagnostic listing endpoint
 * `GET /ui-playground/admin/demo-submissions`.
 *
 * The route is registered as a public payload because the
 * UiPlayground module is dev-facing — but the listing exposes
 * sanitised contact submissions, so apps that mount the playground
 * in any non-dev environment MUST bind a real authorizer.
 *
 * Contract:
 *   - return normally (void) to allow the listing render;
 *   - throw {@see UiDemoSubmissionAdminAuthorizationException} to
 *     deny. The route handler catches the typed exception and
 *     renders a safe `403`-flavoured template state.
 *
 * Default implementation is {@see AllowAllUiDemoSubmissionAdminAuthorizer}
 * — preserves out-of-the-box demo accessibility. Apps override by
 * binding their own implementation with
 * `#[SatisfiesServiceContract(of: UiDemoSubmissionAdminAuthorizerInterface::class)]`
 * inside a module that "extends" semitexa-platform-ui.
 *
 * Implementations MUST NOT mutate state, write logs containing user
 * content, or call services that touch persistence. They are pure
 * decision functions.
 */
interface UiDemoSubmissionAdminAuthorizerInterface
{
    /**
     * @throws UiDemoSubmissionAdminAuthorizationException on deny.
     */
    public function authorize(): void;
}
