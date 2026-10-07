<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Twig;

use Semitexa\PlatformUi\Application\Service\Link\UiSafeHref;
use Semitexa\Ssr\Application\Service\Extension\TwigExtensionRegistry;
use Semitexa\Ssr\Attribute\AsTwigExtension;

#[AsTwigExtension]
final class LinkTwigExtension
{
    public function registerFunctions(): void
    {
        /**
         * ui_href(href)
         *
         * A link target that arrived as data, or '' when it could run script
         * (`javascript:`, `data:`, `//other-host`). Page blocks and the button
         * primitive pass every href through it; '' means "render no link".
         */
        TwigExtensionRegistry::registerFunction(
            'ui_href',
            static fn (mixed $href): string => UiSafeHref::filter($href),
        );
    }
}
