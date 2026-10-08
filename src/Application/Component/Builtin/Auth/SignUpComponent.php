<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin\Auth;

use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;
use Semitexa\Ssr\Attribute\AsComponent;

/**
 * A sign-up form: name, email, a password typed twice and optional terms; the project's action creates the account.
 *
 * Backend-agnostic: the form's fields and rules are here, what happens on
 * submit is the project's #[AsFormSubmitAction] named by `submitAction`.
 */
#[AsComponent(
    name: 'platform.sign-up',
    template: '@platform-ui/components/runtime/auth/sign-up.html.twig',
    cacheable: false,
)]
#[AsUiContract(
    summary: 'A sign-up form: name, email, a password typed twice and optional terms; the project\'s action creates the account.',
    props: [
        new UiProp('submitAction', required: true),
        new UiProp('title', default: 'Create an account'),
        new UiProp('termsHref', default: '', description: 'When set, a required "I accept the terms" checkbox links to it.'),
        new UiProp('signInHref', default: ''),
        new UiProp('minPassword', UiPropType::Integer, default: 12),
    ],
    previewSafe: false,
)]
final class SignUpComponent
{
}
