<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Twig;

use Semitexa\PlatformUi\Application\Service\Access\UiPermissions;
use Semitexa\Ssr\Application\Service\Extension\TwigExtensionRegistry;
use Semitexa\Ssr\Attribute\AsTwigExtension;

#[AsTwigExtension]
final class AccessTwigExtension
{
    public function registerFunctions(): void
    {
        /**
         * can(permission)
         *
         * Does the visitor hold this permission? The same answer a
         * #[RequiresPermission] route gets, for the current request. `null`
         * means "signed in is enough" (a screen declared without a
         * permission). Use it to leave out what would be refused:
         *
         *     {% if can('content.edit') %}<a ui="button" href="…/edit">Edit</a>{% endif %}
         *
         * Hiding is never the check: the route or action refuses on its own.
         */
        TwigExtensionRegistry::registerFunction(
            'can',
            static fn (?string $permission): bool => UiPermissions::permits($permission),
        );

        /** signed_in() — is there a visitor, not a guest? */
        TwigExtensionRegistry::registerFunction(
            'signed_in',
            static fn (): bool => UiPermissions::signedIn(),
        );
    }
}
