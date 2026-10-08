<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

use Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore;
use Semitexa\Ssr\Application\Service\UiEvent\UiSseSessionState;

/**
 * The page's KISS session, as the two inert meta tags the event runtime scans
 * for: `semitexa-ui-sse-session` (minted once per request) and
 * `semitexa-ui-transport-mode` (drain | live). What `ui_page_sse_session_meta()`
 * prints, and what a live island announces into <head> (once per page).
 */
final class UiPageSseSession
{
    /** The <head> key the page's session meta is announced under: one pair per page. */
    public const HEAD_KEY = 'platform-ui.sse-session';

    /**
     * Ask for the page's session meta without printing it: it lands once in
     * <head> when the page is finalized, unless the page printed it itself
     * (`{{ ui_page_sse_session_meta() }}`). This is what a part of a page that
     * needs the live channel calls — an island, say — so that two of them on
     * one page do not leave two copies, and a template author has nothing to
     * write.
     */
    public static function announce(): void
    {
        AssetCollectorStore::get()->headTag(self::HEAD_KEY, self::meta(), 'name="semitexa-ui-sse-session"');
    }

    public static function meta(?string $mode = null): string
    {
        // The auth bit is OPTIONAL request-scoped state pushed in by the
        // consuming app's AuthCheck bridge; null (no bridge) leaves the policy
        // on its drain default. Reading it here — at the request-scoped render
        // boundary — keeps PlatformUiTransportModePolicy pure and auth-agnostic.
        $resolved = (new PlatformUiTransportModePolicy())->resolve($mode, PlatformUiAuthState::current());
        $id = htmlspecialchars(UiSseSessionState::mintIfAbsent(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $modeAttr = htmlspecialchars($resolved->value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<meta name="semitexa-ui-sse-session" content="' . $id . '">'
            . '<meta name="semitexa-ui-transport-mode" content="' . $modeAttr . '">';
    }
}
