<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Event;

/**
 * Token handle returned by UiFormSubmitCsrfTokenStoreInterface::issue().
 *
 * Carries the public token id (logged + signed) and the raw token (the
 * bearer secret signed into cfg.s, hashed-on-store, compared on
 * consume()). Once issue() returns this handle, the store keeps ONLY
 * the hash of the raw value; the raw token MUST be embedded into the
 * signed submit ctx and never logged.
 *
 * Shape rationale:
 *
 *   - `id`   : `uicsrf_<16hex>` — short, attribute-safe, matches the
 *              existing `uci_*` / `frm_*` cosmetic shapes for greppable
 *              operator logs. Acts as the cache key (with namespace).
 *   - `raw`  : 32 hex characters (128 bits of entropy). Big enough to
 *              make brute-force pointless within a 10-minute TTL even
 *              if an attacker mints fresh dispatchIds at full throttle.
 *              The signed ctx carries it verbatim; the cache stores
 *              only `hash_hmac('sha256', $raw, $id)`.
 */
final readonly class UiFormSubmitCsrfTokenHandle
{
    public function __construct(
        public string $id,
        public string $raw,
    ) {}
}
