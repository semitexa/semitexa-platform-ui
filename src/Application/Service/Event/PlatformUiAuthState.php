<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

use Semitexa\Core\Support\CoroutineLocal;

/**
 * Per-request holder for the current request's authentication state,
 * consulted only to pick the DEFAULT SSE transport mode when a page
 * does not explicitly declare one.
 *
 * platform-ui is intentionally auth-agnostic — its composer dependency
 * set (core / llm / ssr / theme) does NOT include semitexa/auth, so
 * this package cannot read AuthSessionSegment / SubjectInterface
 * directly. The value is therefore PUSHED IN by the consuming
 * application: an app-side pipeline listener on the AuthCheck phase
 * reads whatever auth mechanism the app uses and calls {@see self::set()}
 * before any template renders
 * {@see \Semitexa\PlatformUi\Application\Service\Twig\PlatformUiTwigExtension}
 * `ui_page_sse_session_meta()`.
 *
 * Tri-state on purpose:
 *
 *   - `true`  → request is authenticated → auth-derived default is LIVE
 *   - `false` → request is a guest        → auth-derived default is DRAIN
 *   - `null`  → unknown (no app bridge ran, or auth not installed)
 *               → policy keeps its hard DRAIN fallback
 *
 * `null` is the backward-compatible state: an app that never wires the
 * bridge keeps producing drain-by-default pages exactly as before this
 * feature existed. Explicit `ui_page_sse_session_meta('live'|'drain')`
 * and the `SEMITEXA_UI_TRANSPORT_MODE` env both still take precedence
 * over the auth-derived default — see
 * {@see PlatformUiTransportModePolicy::resolve()}.
 *
 * COROUTINE-LOCAL, like {@see PlatformUiSseSessionState}: a Swoole worker
 * serves many requests concurrently on separate coroutines, so a plain
 * process-static would be SHARED — an authenticated request A's `true`
 * would be observable by a concurrent guest request B and silently
 * upgrade it from DRAIN to LIVE (a cross-request auth leak). The value
 * lives in the coroutine context, isolated per request and auto-cleaned
 * when the coroutine ends. {@see ResetPlatformUiSseSessionListener} still
 * resets on the AuthCheck phase (before the app bridge populates the
 * fresh value) — required for the CLI/test fallback store, which persists
 * across requests.
 */
final class PlatformUiAuthState
{
    /** Coroutine-local storage key for the current request's auth state. */
    private const CTX_KEY = 'platform_ui.auth_state';

    /**
     * The current request's auth state, or `null` when unknown (no
     * bridge ran). The transport policy treats `null` and `false`
     * identically (drain); only an explicit `true` upgrades the
     * default to live.
     */
    public static function current(): ?bool
    {
        $value = CoroutineLocal::get(self::CTX_KEY);

        return is_bool($value) ? $value : null;
    }

    /**
     * Record the current request's auth state. Called by the app-side
     * AuthCheck bridge listener. Last write within a request wins.
     */
    public static function set(bool $authenticated): void
    {
        CoroutineLocal::set(self::CTX_KEY, $authenticated);
    }

    /**
     * Clear the holder back to "unknown". Invoked per request before
     * any handler runs or any template renders;
     * see {@see ResetPlatformUiSseSessionListener}.
     */
    public static function reset(): void
    {
        CoroutineLocal::remove(self::CTX_KEY);
    }
}
