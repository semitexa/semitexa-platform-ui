<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

/**
 * Per-render holder for the canonical SSE subscriber channel id every
 * platform-ui component on a page shares.
 *
 * The id is opt-in: pages render `{{ ui_page_sse_session_meta() }}`
 * (or call the lower-level `ui_page_sse_session()` helper) before any
 * platform-ui component renders. That call mints a fresh id; every
 * subsequent `ui_event_manifest()` call within the same render picks
 * it up via {@see self::current()} and folds it into the signed ctx
 * as the `sub` claim. Pages that DO NOT mint a session id keep
 * producing manifests with no `sub` claim — the dispatcher then
 * delivers patches inline, preserving the pre-canonical-SSE behaviour.
 *
 * The state is intentionally per-process. Swoole workers are long-
 * lived, so without an explicit reset the same id would survive
 * across requests and let a later request unknowingly publish patches
 * to an earlier request's subscriber stream. The reset is performed
 * by {@see ResetPlatformUiSseSessionListener} during the AuthCheck
 * pipeline phase (canonical request-scoped integration point per
 * AGENTS.md and the framework-traps notes).
 *
 * Why not a Twig context variable: `ui_event_manifest()` is called
 * from inner component templates (e.g. platform.form, platform.field)
 * whose render contexts do not flow caller variables in. A static
 * holder is the simplest cross-template channel that does not require
 * editing every component to forward an extra prop.
 *
 * Why not the existing UiSseChannelToken: that class signs an opaque
 * stream-subscription credential for the platform-ui `/__ui/stream`
 * route. The canonical KISS endpoint takes an UNSIGNED `session_id`
 * query parameter and routes by it directly. The two systems share
 * no key material; minting `uch_*` ids for KISS would be misleading.
 * The dedicated `sse_<32 hex>` prefix keeps logs disambiguated.
 */
final class PlatformUiSseSessionState
{
    /**
     * Safe shape for a subscriber channel id. Same alphabet
     * {@see UiEventManifestBuilder} accepts on the `sub` claim and
     * {@see \Semitexa\PlatformUi\Application\Service\Event\PlatformUiResponseDispatcher}
     * re-validates after verification.
     */
    public const SAFE_ID_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9_-]{0,127}\z/';

    /**
     * 16 random bytes → 32 hex chars → 128 bits of entropy. The
     * `sse_` prefix makes the id distinguishable in Swoole / KISS
     * server logs from other session families (`uch_` for the legacy
     * channel-token route, `uci_` for component instance ids).
     */
    private const PREFIX = 'sse_';
    private const ENTROPY_BYTES = 16;

    private static ?string $current = null;

    public static function current(): ?string
    {
        return self::$current;
    }

    /**
     * Mints a fresh id only on the first call within a request.
     * Subsequent calls within the same request return the same id —
     * crucial so every platform-ui component on the page ends up
     * with the same `sub` claim and the runtime opens a single SSE
     * connection.
     */
    public static function mintIfAbsent(): string
    {
        if (self::$current !== null) {
            return self::$current;
        }
        self::$current = self::PREFIX . bin2hex(random_bytes(self::ENTROPY_BYTES));
        return self::$current;
    }

    public static function reset(): void
    {
        self::$current = null;
    }

    /**
     * Test seam: callers MAY pre-seed a deterministic id so unit
     * tests can assert on the exact value rendered into the page.
     * The id MUST match {@see self::SAFE_ID_PATTERN}; this guard
     * keeps the test surface consistent with what the manifest
     * builder accepts in production.
     */
    public static function setForTesting(string $id): void
    {
        if (preg_match(self::SAFE_ID_PATTERN, $id) !== 1) {
            throw new \InvalidArgumentException(
                'PlatformUiSseSessionState::setForTesting() id must match ' . self::SAFE_ID_PATTERN,
            );
        }
        self::$current = $id;
    }
}
