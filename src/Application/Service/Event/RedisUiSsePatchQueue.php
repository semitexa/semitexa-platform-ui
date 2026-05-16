<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

use Predis\ClientInterface;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Environment;
use Semitexa\Core\Redis\RedisConnectionPool;

/**
 * Redis-backed implementation of UiSsePatchQueue.
 *
 * Wire shape:
 *   key   = "semitexa:ui-patch-queue:<channelId>"
 *   value = JSON-encoded SSE payload (already prepared by publisher)
 *   ops   = RPUSH on publish, LPOP / LRANGE+LTRIM on drain.
 *   ttl   = QUEUE_IDLE_TTL_SECONDS — bounds abandoned-channel growth.
 *
 * Default service binding: registered via SatisfiesServiceContract so
 * the publisher's container-managed instance resolves to this in
 * production. Apps can override per the standard
 * descendant-module-wins rule.
 *
 * Atomicity / delivery semantics:
 *   - drain() reads with LPOP — at-most-once within the queue's TTL.
 *   - When Redis is unreachable, drain() returns an empty list and
 *     publish() falls through to a no-op (with a logged warning); the
 *     dispatcher's runtime guard already enforces the
 *     shared-cache-required policy in production, so a misconfigured
 *     install cannot silently lose patches.
 */
#[SatisfiesServiceContract(of: UiSsePatchQueue::class)]
final class RedisUiSsePatchQueue implements UiSsePatchQueue
{
    private const KEY_PREFIX = 'semitexa:ui-patch-queue:';

    /**
     * Idle TTL applied on each publish. A subscriber that never
     * connects (or disconnects without coming back) will see the queue
     * auto-evicted after this many seconds without any new traffic.
     */
    private const QUEUE_IDLE_TTL_SECONDS = 60;

    private ?RedisConnectionPool $pool = null;

    public function publish(string $channelId, string $jsonPayload): void
    {
        $pool = $this->resolvePool();
        if ($pool === null) {
            // No Redis configured. The dispatcher's production guard
            // already refuses dispatch under this configuration, so
            // reaching publish() here means dev/test — drop silently.
            return;
        }
        $key = self::KEY_PREFIX . $channelId;
        try {
            $pool->withConnection(static function (ClientInterface $redis) use ($key, $jsonPayload): void {
                $redis->rpush($key, [$jsonPayload]);
                $redis->expire($key, self::QUEUE_IDLE_TTL_SECONDS);
            });
        } catch (\Throwable) {
            // Best-effort: log via the static bridge if available;
            // otherwise stay quiet to keep the publisher path
            // resilient.
            if (class_exists(\Semitexa\Core\Log\StaticLoggerBridge::class)) {
                \Semitexa\Core\Log\StaticLoggerBridge::error('platform-ui', 'Redis SSE publish failed', [
                    'channel' => $channelId,
                ]);
            }
        }
    }

    public function drain(string $channelId, int $limit): array
    {
        $pool = $this->resolvePool();
        if ($pool === null || $limit <= 0) {
            return [];
        }
        $key = self::KEY_PREFIX . $channelId;
        try {
            /** @var list<string> $batch */
            $batch = $pool->withConnection(static function (ClientInterface $redis) use ($key, $limit): array {
                $out = [];
                for ($i = 0; $i < $limit; $i++) {
                    $value = $redis->lpop($key);
                    if ($value === null || $value === false) {
                        break;
                    }
                    $out[] = (string) $value;
                }
                return $out;
            });
            return $batch;
        } catch (\Throwable) {
            if (class_exists(\Semitexa\Core\Log\StaticLoggerBridge::class)) {
                \Semitexa\Core\Log\StaticLoggerBridge::error('platform-ui', 'Redis SSE drain failed', [
                    'channel' => $channelId,
                ]);
            }
            return [];
        }
    }

    /**
     * Test seam: bypass env-driven pool construction and inject a
     * specific RedisConnectionPool. Production uses the lazily-resolved
     * pool below.
     */
    public function withPool(RedisConnectionPool $pool): self
    {
        $this->pool = $pool;
        return $this;
    }

    private function resolvePool(): ?RedisConnectionPool
    {
        if ($this->pool instanceof RedisConnectionPool) {
            return $this->pool;
        }
        $host = Environment::getEnvValue('REDIS_HOST');
        if ($host === null || $host === '') {
            return null;
        }
        $this->pool = new RedisConnectionPool(1, [
            'scheme'   => (string) (Environment::getEnvValue('REDIS_SCHEME', 'tcp') ?? 'tcp'),
            'host'     => $host,
            'port'     => (int) (Environment::getEnvValue('REDIS_PORT', '6379') ?? '6379'),
            'password' => (string) (Environment::getEnvValue('REDIS_PASSWORD', '') ?? ''),
        ]);
        return $this->pool;
    }
}
