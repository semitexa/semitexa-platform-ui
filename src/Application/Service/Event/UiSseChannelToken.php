<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;

/**
 * Signs / verifies the opaque token a browser uses to subscribe to a
 * Platform UI SSE patch stream.
 *
 * Built on top of SignedContext so we inherit:
 *   - HMAC-SHA256 over claims with APP_SECRET;
 *   - `sc1.<base64url(json)>.<base64url(hmac)>` wire format;
 *   - iat/exp TTL semantics.
 *
 * Claim shape (server-side only):
 *   c   = 'ui-patch-stream'      // purpose marker — defends against
 *                                 // mixing /__ui/dispatch ctx tokens
 *                                 // and SSE channel tokens.
 *   ch  = '<channel-id>'         // opaque channel identifier (caller-chosen).
 *   iat = unix ts.
 *   exp = unix ts.
 *
 * The token MUST NOT carry handler names, component class FQCNs, user
 * ids, or any other server identity — it is exclusively a channel
 * subscription credential.
 */
final class UiSseChannelToken
{
    /**
     * Fixed purpose claim. Prevents a /__ui/dispatch ctx (which has
     * different claims) from being accepted as a stream subscription.
     */
    public const PURPOSE_CLAIM = 'ui-patch-stream';

    /**
     * Default TTL for a stream subscription. Connections older than
     * this will time out client-side at reconnect; server enforces its
     * own max-age cap (SSE_MAX_CONNECTION_AGE_SECONDS) independently.
     */
    public const DEFAULT_TTL_SECONDS = 600;

    /**
     * Channel id format. Bounded length, opaque alphabet — mirrors the
     * dispatchId regex so the same character class works everywhere.
     */
    public const CHANNEL_ID_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9_-]{4,127}\z/';

    public static function sign(string $channelId, ?int $ttlSeconds = null): string
    {
        if (preg_match(self::CHANNEL_ID_PATTERN, $channelId) !== 1) {
            throw new \InvalidArgumentException(
                'channelId must be 5–128 chars of [A-Za-z0-9_-] starting with an alphanumeric.',
            );
        }
        return SignedContext::sign(
            [
                'c'  => self::PURPOSE_CLAIM,
                'ch' => $channelId,
            ],
            $ttlSeconds ?? self::DEFAULT_TTL_SECONDS,
        );
    }

    /**
     * Verify the token and return the channel id, or null on any error
     * (bad signature, expired, wrong purpose, malformed channel id).
     * The caller must treat null as "reject; do not subscribe".
     */
    public static function verifyChannelId(string $token): ?string
    {
        $claims = SignedContext::verify($token);
        if ($claims === null) {
            return null;
        }
        $purpose = $claims['c'] ?? null;
        if (!is_string($purpose) || $purpose !== self::PURPOSE_CLAIM) {
            return null;
        }
        $channel = $claims['ch'] ?? null;
        if (!is_string($channel) || preg_match(self::CHANNEL_ID_PATTERN, $channel) !== 1) {
            return null;
        }
        return $channel;
    }

    /**
     * Generate a fresh channel id. Format: `uch_<32 hex>` — 128 bits of
     * entropy, prefixed so logs / Redis keys identify the source.
     */
    public static function generateChannelId(): string
    {
        return 'uch_' . bin2hex(random_bytes(16));
    }
}
