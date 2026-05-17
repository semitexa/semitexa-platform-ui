<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\PlatformUi\Application\Service\Event\UiInstanceIdGenerator;
use Semitexa\PlatformUi\Domain\Exception\UiFormSubmitSecurityPolicyException;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitSecurityContext;

/**
 * Cache-backed CSRF token verification policy. Replaces
 * {@see SignedContextOnlyUiFormSubmitSecurityPolicy} as the default
 * binding for {@see UiFormSubmitSecurityPolicyInterface}.
 *
 * Flow:
 *
 *   - Render time: when FormComponent has `submitAction`, the form
 *     template calls `ui_form_issue_submit_csrf()` which mints a fresh
 *     `{id, raw}` pair via {@see UiFormSubmitCsrfTokenStore} and signs
 *     `cfg.s = {k: <id>, t: <raw>}` into the submit context.
 *   - Dispatch time: the dispatcher verifies the HMAC, FormComponent's
 *     onSubmit() builds a security context carrying the verified
 *     `cfg.s` map, and this policy:
 *       1. Asserts `cfg.s.k` + `cfg.s.t` exist and have the documented
 *          safe shape (`uicsrf_<16hex>` + 32-hex token).
 *       2. Calls {@see UiFormSubmitCsrfTokenStoreInterface::consume()}
 *          which atomically verifies + removes the entry. The entry
 *          is keyed by the token id; the stored value is the HMAC
 *          hash of the raw token.
 *       3. Returns void on success; throws
 *          {@see UiFormSubmitSecurityPolicyException} with
 *          `csrf_verification_failed` on any failure.
 *
 * One-time semantics:
 *
 *   The policy consumes the token on successful verification — a
 *   second submit attempt with the same signed ctx but a fresh
 *   dispatchId fails CSRF. This is the intended behaviour: it
 *   replaces "client cannot resubmit until they reload the form".
 *
 *   The policy runs AFTER field validation + action authorizer. So:
 *
 *     - invalid submits never reach `consume()` → token survives;
 *     - authorizer-denied submits never reach `consume()` → token
 *       survives;
 *     - valid + authorized submits consume the token regardless of
 *       whether the action itself rejects (acceptable — the user
 *       still saw a server response, which is enough to invalidate
 *       the bearer secret).
 *
 * Trust perimeter:
 *
 *   - The stored value is HMAC-only. The raw token never lives in
 *     the cache, so a leaked cache snapshot cannot be replayed.
 *   - Failure messages and reason codes never echo the bad token id
 *     / value back, even at the safe-error layer.
 *   - The exception type does not vary between missing / expired /
 *     mismatched / consumed — all collapse into the same
 *     `csrf_verification_failed` reason.
 *
 * Limitations (documented in primitives.md):
 *
 *   - This is NOT session-bound — the token is bound to the rendered
 *     form via the signed ctx, not to a session cookie. A leaked
 *     full-page HTML (with the signed ctx) is still single-submit by
 *     this policy, but the same render can be submitted from any
 *     UA. True session binding lands when Semitexa lands a
 *     request-scoped seam reachable from a reflection-instantiated
 *     component.
 *   - No CSRF token rotation across multiple forms on the same page —
 *     each FormComponent issues its own independent token.
 */
#[SatisfiesServiceContract(of: UiFormSubmitSecurityPolicyInterface::class)]
final class CacheBackedUiFormSubmitSecurityPolicy implements UiFormSubmitSecurityPolicyInterface
{
    /** Matches CacheBackedUiFormSubmitCsrfTokenStore::ID_PREFIX. */
    private const TOKEN_ID_PATTERN = '/\Auicsrf_[a-f0-9]{16}\z/';

    /** 32 hex chars = 128 bits of entropy emitted by the store. */
    private const TOKEN_RAW_PATTERN = '/\A[a-f0-9]{32}\z/';

    public function verify(UiFormSubmitSecurityContext $context): void
    {
        $cfg = $context->securityConfig;

        $tokenId = $cfg['k'] ?? null;
        $rawToken = $cfg['t'] ?? null;

        if (!is_string($tokenId) || preg_match(self::TOKEN_ID_PATTERN, $tokenId) !== 1) {
            throw self::failure();
        }
        if (!is_string($rawToken) || preg_match(self::TOKEN_RAW_PATTERN, $rawToken) !== 1) {
            throw self::failure();
        }
        // Defence in depth: token id shape stays inside the
        // documented uicsrf_ space. UiInstanceIdGenerator::isSafe()
        // covers the uci_ shape (different prefix) so we use the
        // tighter pattern above instead.
        if (UiInstanceIdGenerator::isSafe($tokenId)) {
            // uici_ ids are NOT csrf ids — refuse to consume even by
            // accident.
            throw self::failure();
        }
        if (!UiFormSubmitCsrfTokenStore::getActive()->consume($tokenId, $rawToken)) {
            throw self::failure();
        }
    }

    private static function failure(): UiFormSubmitSecurityPolicyException
    {
        // SAFE message — never echoes the bad id/token, never
        // discloses which arm failed (missing / expired / wrong /
        // already consumed).
        return new UiFormSubmitSecurityPolicyException(
            message: 'Submit security check failed. Please reload the form and try again.',
            reasonCode: 'csrf_verification_failed',
        );
    }
}
