<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

/**
 * Per-channel FIFO queue used by the Platform UI SSE patch path.
 *
 * Implementations:
 *   - RedisUiSsePatchQueue — production, cross-worker via Redis LIST.
 *   - InMemoryUiSsePatchQueue — tests and single-worker dev fallback.
 *
 * Semantics:
 *   - publish() appends one encoded SSE payload (already a JSON string)
 *     to the channel's queue.
 *   - drain() removes and returns up to $limit entries in FIFO order.
 *   - Implementations are responsible for bounding the queue's lifetime
 *     (idle TTL) so abandoned channels do not accumulate.
 *
 * Atomicity isn't required for this proof slice — at-most-once delivery
 * within a TTL window is sufficient. Future hardening can swap in
 * pub/sub semantics without changing this interface.
 */
interface UiSsePatchQueue
{
    /**
     * Append a single payload to $channelId's queue. $jsonPayload is
     * already JSON-encoded; the queue MUST NOT re-encode or otherwise
     * mutate it.
     */
    public function publish(string $channelId, string $jsonPayload): void;

    /**
     * Pop up to $limit payloads from the queue in FIFO order. Returns
     * an empty list when the queue is empty.
     *
     * @return list<string>
     */
    public function drain(string $channelId, int $limit): array;
}
