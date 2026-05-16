<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

/**
 * Worker-local FIFO queue for SSE patch payloads. Same caveats as
 * InMemoryUiReplayStore: per-worker map, NOT safe across multiple
 * Swoole workers. Used by tests and as a last-resort fallback when the
 * publisher's Redis pool is unavailable.
 *
 * Production deployments wire RedisUiSsePatchQueue via service
 * contracts so cross-worker subscriptions still observe published
 * patches.
 */
final class InMemoryUiSsePatchQueue implements UiSsePatchQueue
{
    /** @var array<string, list<string>> channelId → queued JSON payloads */
    private array $queues = [];

    public function publish(string $channelId, string $jsonPayload): void
    {
        if (!isset($this->queues[$channelId])) {
            $this->queues[$channelId] = [];
        }
        $this->queues[$channelId][] = $jsonPayload;
    }

    public function drain(string $channelId, int $limit): array
    {
        if (!isset($this->queues[$channelId]) || $this->queues[$channelId] === []) {
            return [];
        }
        if ($limit <= 0) {
            return [];
        }
        $batch = array_splice($this->queues[$channelId], 0, $limit);
        if ($this->queues[$channelId] === []) {
            unset($this->queues[$channelId]);
        }
        return $batch;
    }

    /** Test reset hook. */
    public function reset(): void
    {
        $this->queues = [];
    }
}
