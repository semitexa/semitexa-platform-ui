<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

/**
 * Opaque handle returned by UiSseConnectionLimiterInterface::claim().
 * The handler hands the lease back to release() in a finally block so
 * the per-IP and global counters drop back down promptly.
 *
 * The lease id is randomized so concurrent claims from the same IP
 * (same channel even) don't collide on the same set member.
 */
final readonly class UiSseConnectionLease
{
    public function __construct(
        public string $id,
        public string $ip,
        public string $channelId,
        public int    $issuedAt,
        public int    $ttlSeconds,
    ) {}
}
