<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin\Auth;

use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;
use Semitexa\Ssr\Attribute\AsComponent;

/**
 * A sign-in form on platform.form: an identifier, a password and "keep me signed in"; the project names the action that checks them.
 *
 * Backend-agnostic: the form's fields and rules are here, what happens on
 * submit is the project's #[AsFormSubmitAction] named by `submitAction`.
 */
#[AsComponent(
    name: 'platform.sign-in',
    template: '@platform-ui/components/runtime/auth/sign-in.html.twig',
    cacheable: false,
)]
#[AsUiContract(
    summary: 'A sign-in form on platform.form: an identifier, a password and "keep me signed in"; the project names the action that checks them.',
    props: [
        new UiProp('submitAction', required: true, description: 'The #[AsFormSubmitAction] that signs the visitor in.'),
        new UiProp('identifier', default: 'email', values: ['email', 'username']),
        new UiProp('title', default: 'Sign in'),
        new UiProp('forgotHref', default: '', description: 'Link to the forgot-password page.'),
        new UiProp('signUpHref', default: '', description: 'Link to the sign-up page.'),
        new UiProp('remember', UiPropType::Boolean, default: true),
    ],
    previewSafe: false,
)]
final class SignInComponent
{
}
