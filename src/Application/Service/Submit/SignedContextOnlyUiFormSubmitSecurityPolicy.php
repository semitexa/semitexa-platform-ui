<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit;

use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitSecurityContext;

/**
 * Demo-only UiFormSubmitSecurityPolicyInterface implementation —
 * permits every submit that has already cleared the upstream gates.
 *
 * As of the CSRF-policy slice this class is **no longer the default
 * service binding**: {@see CacheBackedUiFormSubmitSecurityPolicy}
 * now carries `#[SatisfiesServiceContract]` and is auto-discovered
 * as the default. This class stays in the tree as a deliberate
 * opt-in for environments where the cache layer is not available
 * (e.g. unit tests that bypass bootstrap, or `CACHE_DRIVER=array`
 * environments). Apps that want to opt back into it bind it
 * explicitly in their own module.
 *
 * Reasoning for the original "demo-safe" stance — preserved for
 * historical reference because the same trust-stack still applies
 * for the upstream gates this policy trusts:
 *
 *   - `cfg.a` is server-signed and tampering breaks HMAC.
 *   - `dispatchId` is replay-guarded, so a captured request body can
 *     be submitted exactly once.
 *   - `UiPayloadFieldGuard` rejects routing/config keys in the
 *     payload, including `csrf` / `submitAction` etc.
 *
 * This is **NOT sufficient** once a submit starts persisting data:
 *
 *   - cross-site form forgery is still possible if a logged-in user
 *     is tricked into loading an attacker-crafted page that holds a
 *     valid signed ctx;
 *   - session-bound rate limiting / token rotation cannot run here;
 *   - audit identity (who-did-what) is not captured.
 *
 * When persistence lands, apps replace this policy by binding their
 * own implementation with
 * `#[SatisfiesServiceContract(of: UiFormSubmitSecurityPolicyInterface::class)]`.
 * The contract registry picks the descendant-module winner; the
 * BootPlatformUiRegistryListener stashes that winner in
 * UiFormSubmitSecurityPolicy.
 *
 * Until then this class is the canonical demo-safe no-op.
 */
final class SignedContextOnlyUiFormSubmitSecurityPolicy implements UiFormSubmitSecurityPolicyInterface
{
    public function verify(UiFormSubmitSecurityContext $context): void
    {
        // No-op by design. See class doc for the perimeter this
        // policy intentionally trusts (signed ctx + replay guard +
        // payload guard) and the cases where it is NOT enough
        // (persistence, audit, session-bound CSRF).
    }
}
