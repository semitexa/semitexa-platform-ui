<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

/**
 * Immutable DTO passed to UiSseSubscriptionAuthorizerInterface and to
 * UiSseConnectionLimiterInterface. Carries everything the dispatcher /
 * limiter need to make a per-subscription decision — and nothing more.
 *
 * The token is NOT included. The handler has already verified it and
 * extracted the channelId; passing the raw blob around would only
 * encourage downstream code to re-verify (or worse, decode) it.
 *
 * `requestIp` is the resolved client IP from the Swoole request's
 * `remote_addr` (or '' if unresolvable). Used by the connection
 * limiter; authorizers may also inspect it (e.g. for tenant-by-IP
 * lookups) but MUST NOT treat it as authentication.
 *
 * `claims` is the raw signed-context claims map — purpose/channel id
 * are already lifted out into typed fields above, but apps that build
 * richer authorizers (e.g. inspecting custom claims they minted into
 * the token) can read them here.
 */
final readonly class UiSseSubscriptionContext
{
    /**
     * @param array<string, mixed> $claims Verified claims as decoded
     *        from the channel token. Server-side only; do NOT echo
     *        verbatim in responses.
     */
    public function __construct(
        public string $channelId,
        public string $purpose,
        public int    $issuedAt,
        public int    $expiresAt,
        public string $requestIp,
        public array  $claims = [],
    ) {}
}
