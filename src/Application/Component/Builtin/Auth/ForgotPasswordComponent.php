<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin\Auth;

use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\Ssr\Attribute\AsComponent;

/**
 * Ask for a reset link by email; the action answers the same way whether or not the address exists.
 *
 * The form's fields and rules are here; what happens on submit is the
 * #[AsFormSubmitAction] named by `submitAction`: the ready `platform.auth.forgot-password`
 * (semitexa/platform-user accounts), or the project's own.
 */
#[AsComponent(
    name: 'platform.forgot-password',
    template: '@platform-ui/components/runtime/auth/forgot-password.html.twig',
    cacheable: false,
)]
#[AsUiContract(
    summary: 'Ask for a reset link by email; the action answers the same way whether or not the address exists.',
    props: [
        new UiProp('submitAction', required: true),
        new UiProp('title', default: 'Reset your password'),
        new UiProp('signInHref', default: ''),
    ],
    previewSafe: false,
)]
final class ForgotPasswordComponent
{
}
