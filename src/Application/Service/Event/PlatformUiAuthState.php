<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

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
 * Per-process, like {@see PlatformUiSseSessionState}: Swoole workers
 * persist between requests, so the value MUST be reset at the start of
 * every request or request A's authenticated state would leak into a
 * later guest request B and silently upgrade it to live.
 * {@see ResetPlatformUiSseSessionListener} performs the reset on the
 * AuthCheck phase (the canonical request-scoped join point) at the
 * lowest priority, so the reset runs before the app bridge populates
 * the fresh value.
 */
final class PlatformUiAuthState
{
    private static ?bool $authenticated = null;

    /**
     * The current request's auth state, or `null` when unknown (no
     * bridge ran). The transport policy treats `null` and `false`
     * identically (drain); only an explicit `true` upgrades the
     * default to live.
     */
    public static function current(): ?bool
    {
        return self::$authenticated;
    }

    /**
     * Record the current request's auth state. Called by the app-side
     * AuthCheck bridge listener. Last write within a request wins.
     */
    public static function set(bool $authenticated): void
    {
        self::$authenticated = $authenticated;
    }

    /**
     * Clear the holder back to "unknown". Invoked per request before
     * any handler runs or any template renders;
     * see {@see ResetPlatformUiSseSessionListener}.
     */
    public static function reset(): void
    {
        self::$authenticated = null;
    }
}
