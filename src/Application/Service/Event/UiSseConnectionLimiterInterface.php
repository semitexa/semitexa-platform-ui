<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

use Semitexa\PlatformUi\Domain\Exception\UiSseConnectionLimitException;

/**
 * Per-IP and global connection cap for /__ui/stream.
 *
 * Implementations are responsible for:
 *   - counting currently-open SSE subscriptions across all Swoole
 *     workers / processes that share the same store;
 *   - rejecting new claims that would exceed `SSE_MAX_CONN_PER_IP` or
 *     `SSE_MAX_CONN_GLOBAL` (env names reused from SSR — least
 *     surprise);
 *   - issuing each accepted claim a TTL-bounded UiSseConnectionLease
 *     so a leaked connection (network drop, worker crash, missed
 *     release()) is bounded in damage by the lease TTL.
 *
 * The contract is intentionally minimal — no listing, no counting
 * accessor, no admin endpoints. Operators inspect the underlying
 * store (Redis) directly when needed.
 */
interface UiSseConnectionLimiterInterface
{
    /**
     * Reserve a slot for $context. Throws
     * UiSseConnectionLimitException (HTTP 429, reason
     * `sse_connection_limit_exceeded`) when a cap is saturated; the
     * caller MUST NOT open the stream in that case.
     *
     * @throws UiSseConnectionLimitException
     */
    public function claim(UiSseSubscriptionContext $context): UiSseConnectionLease;

    /**
     * Release a previously-claimed lease. Implementations MUST be
     * idempotent — the handler typically calls release() in a finally
     * block, and TTL expiry may already have removed the lease.
     */
    public function release(UiSseConnectionLease $lease): void;
}
