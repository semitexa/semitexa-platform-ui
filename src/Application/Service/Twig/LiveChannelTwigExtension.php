<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Twig;

use Semitexa\PlatformUi\Application\Service\Event\UiPageSseSession;
use Semitexa\Ssr\Application\Service\Extension\TwigExtensionRegistry;
use Semitexa\Ssr\Attribute\AsTwigExtension;

/**
 * ui_page_live_channel() — the quiet way for a template to say "this page needs
 * its live channel".
 *
 * Prints nothing. The page's session meta lands in <head> once, when the page
 * is finalized, however many parts of it asked ({@see UiPageSseSession::announce()}).
 * What a shared template that rides the KISS stream calls — a grid, say — where
 * printing the meta itself left one copy per instance on the page. A page that
 * wants a specific transport mode still prints
 * `ui_page_sse_session_meta('live'|'drain')`, and then this adds nothing.
 */
#[AsTwigExtension]
final class LiveChannelTwigExtension
{
    public function registerFunctions(): void
    {
        TwigExtensionRegistry::registerFunction('ui_page_live_channel', static function (): string {
            UiPageSseSession::announce();

            return '';
        });
    }
}
