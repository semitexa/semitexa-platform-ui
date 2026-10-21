<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin\Auth;

use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;
use Semitexa\Ssr\Attribute\AsComponent;

/**
 * Choose a new password; the reset token rides the form's SIGNED props (the action reads \$context->props['token']), never a field.
 *
 * The form's fields and rules are here; what happens on submit is the
 * #[AsFormSubmitAction] named by `submitAction`: the ready `platform.auth.reset-password`
 * (semitexa/platform-user accounts), or the project's own.
 */
#[AsComponent(
    name: 'platform.reset-password',
    template: '@platform-ui/components/runtime/auth/reset-password.html.twig',
    cacheable: false,
)]
#[AsUiContract(
    summary: 'Choose a new password; the reset token rides the form\'s SIGNED props (the action reads \$context->props[\'token\']), never a field.',
    props: [
        new UiProp('submitAction', required: true),
        new UiProp('token', required: true, sensitive: true, description: 'The reset token from the link, signed into the form.'),
        new UiProp('title', default: 'Choose a new password'),
        new UiProp('minPassword', UiPropType::Integer, default: 12),
        new UiProp('signInHref', default: '', description: 'Where platform.auth.reset-password sends the browser after a reset: a path on this site.'),
    ],
    previewSafe: false,
)]
final class ResetPasswordComponent
{
}
