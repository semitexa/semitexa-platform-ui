<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

use Semitexa\PlatformUi\Domain\Exception\UiTransportModeException;

/**
 * Resolves the canonical SSE transport mode the platform-ui page
 * should advertise on the `<meta name="semitexa-ui-transport-mode">`
 * tag and embed in the `/__semitexa_kiss?mode=…` URL.
 *
 * Precedence (highest first):
 *
 *   1. **Explicit page/component option** — the `$mode` argument
 *      forwarded from `ui_page_sse_session_meta(\$mode)`. A page or
 *      component that knows it wants live streaming (admin dashboard,
 *      operator console) opts in by passing the literal `'live'`.
 *      Conversely, a normally-live surface can downgrade per render
 *      by passing `'drain'`.
 *   2. **Env default** — `SEMITEXA_UI_TRANSPORT_MODE` lets an operator
 *      flip the deployment-wide default without redeploying templates.
 *      Typical use: an internal-only deployment exports
 *      `SEMITEXA_UI_TRANSPORT_MODE=live` so every page on the trusted
 *      surface gets live streaming without per-template plumbing.
 *      Public/guest deployments leave this unset.
 *   3. **Hard fallback** — {@see PlatformUiTransportMode::default()}
 *      (drain). Chosen so a brand-new platform-ui page with no
 *      explicit option, on a deployment with no env opt-in, is safe
 *      for public/guest traffic: the runtime never opens a long-lived
 *      EventSource on DOMContentLoaded.
 *
 * Invalid values fail fast at render time on both surfaces. An
 * explicit `$mode` outside the allow-list raises
 * {@see UiTransportModeException} so a Twig dev error surfaces the
 * typo rather than silently degrading. An invalid
 * `SEMITEXA_UI_TRANSPORT_MODE` raises the same exception so a
 * mis-deployed env value never silently widens the public attack
 * surface to live. (The Semitexa convention everywhere else in the
 * platform-ui package — see
 * {@see \Semitexa\PlatformUi\Application\Service\Twig\PlatformUiTwigExtension::registerFunctions()}
 * `ui_form_resolve_submit_action()` — is "throw at the boundary, do
 * not log-and-guess".)
 *
 * The policy is stateless and intentionally not a #[SatisfiesServiceContract]
 * — the resolution depends only on its argument + an env read, and
 * the call sites (Twig helper, unit tests, manual factories) all
 * instantiate it directly. Promoting it to a contract would buy
 * substitutability we don't have a customer for.
 */
final class PlatformUiTransportModePolicy
{
    public const ENV_VAR_NAME = 'SEMITEXA_UI_TRANSPORT_MODE';

    public function resolve(?string $explicitMode): PlatformUiTransportMode
    {
        if ($explicitMode !== null) {
            return self::parseOrThrow($explicitMode, 'ui_page_sse_session_meta() explicit transport mode');
        }

        $envRaw = (string) (\getenv(self::ENV_VAR_NAME) ?: '');
        if ($envRaw !== '') {
            return self::parseOrThrow($envRaw, self::ENV_VAR_NAME);
        }

        return PlatformUiTransportMode::default();
    }

    /**
     * Strict parse: allow-list is exactly the enum's string cases.
     * No trimming, no case-folding — `'DRAIN'` / `' live '` are
     * deployment typos and must surface as errors, same as `'foo'`.
     */
    private static function parseOrThrow(string $raw, string $source): PlatformUiTransportMode
    {
        $mode = PlatformUiTransportMode::tryFrom($raw);
        if ($mode === null) {
            throw new UiTransportModeException(
                sprintf(
                    'Invalid %s value %s — allowed: "drain", "live".',
                    $source,
                    self::formatRawForMessage($raw),
                ),
                $raw,
            );
        }
        return $mode;
    }

    /**
     * Format the raw value for the exception message without echoing
     * arbitrary bytes that might trip downstream log parsers.
     * Constrained to printable ASCII; anything else is summarised by
     * length only.
     */
    private static function formatRawForMessage(string $raw): string
    {
        if ($raw === '') {
            return '""';
        }
        if (preg_match('/\A[\x20-\x7e]{1,32}\z/', $raw) === 1) {
            return '"' . $raw . '"';
        }
        return sprintf('<%d byte(s)>', strlen($raw));
    }
}
