<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

use Predis\ClientInterface;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Environment;
use Semitexa\Core\Redis\RedisConnectionPool;
use Semitexa\PlatformUi\Domain\Exception\UiSseConnectionLimitException;

/**
 * Redis-backed connection limiter — production default.
 *
 * State model:
 *   - Per-IP set:   semitexa:ui-sse-conn:per-ip:<ip>   members = lease ids
 *   - Global set:   semitexa:ui-sse-conn:global        members = lease ids
 *
 * Operations:
 *   - claim: SCARD per-ip → reject when ≥ SSE_MAX_CONN_PER_IP;
 *            SCARD global → reject when ≥ SSE_MAX_CONN_GLOBAL;
 *            SADD lease id to both, EXPIRE both with lease TTL.
 *   - release: SREM from both.
 *
 * Leak budget:
 *   When the worker crashes mid-stream, leases stay in Redis until
 *   their TTL expires. The TTL is set on the WHOLE set, not per-member
 *   (Redis sets don't have per-member TTL), so each EXPIRE refresh
 *   re-arms the set's eviction. In the worst case, a dropped stream
 *   leaks a single lease id for at most $leaseTtlSeconds. Tune
 *   SSE_MAX_CONNECTION_AGE_SECONDS to keep leases short.
 *
 * Atomicity:
 *   The check-then-add pattern has a tiny race window (two concurrent
 *   claims at the boundary). Acceptable for a soft cap; refusing to
 *   admit one extra connection per worker per claim attempt is well
 *   within the per-IP and global cap budgets typical operators use.
 */
#[SatisfiesServiceContract(of: UiSseConnectionLimiterInterface::class)]
final class RedisUiSseConnectionLimiter implements UiSseConnectionLimiterInterface
{
    public const PER_IP_KEY_PREFIX = 'semitexa:ui-sse-conn:per-ip:';
    public const GLOBAL_KEY = 'semitexa:ui-sse-conn:global';

    private const DEFAULT_MAX_PER_IP = 5;
    private const DEFAULT_MAX_GLOBAL = 500;

    /**
     * Lease TTL. Defaults to a bit longer than the SSE stream's max
     * age so a clean release races ahead of TTL eviction; if an
     * operator tweaks SSE_MAX_CONNECTION_AGE_SECONDS upward, this
     * value tracks via getLeaseTtlSeconds() below.
     */
    private const DEFAULT_LEASE_TTL_SECONDS = 700;

    /** Documented in case the framework default for stream max age changes. */
    private const DEFAULT_STREAM_MAX_AGE_SECONDS = 600;

    private ?RedisConnectionPool $pool = null;
    private ?InMemoryUiSseConnectionLimiter $inMemoryFallback = null;

    public function claim(UiSseSubscriptionContext $context): UiSseConnectionLease
    {
        $pool = $this->resolvePool();
        if ($pool === null) {
            return $this->fallback()->claim($context);
        }

        $maxPerIp = $this->resolveMaxPerIp();
        $maxGlobal = $this->resolveMaxGlobal();
        $ttl = $this->resolveLeaseTtlSeconds();
        $ip = $context->requestIp;
        $leaseId = 'uscl_' . bin2hex(random_bytes(16));

        try {
            /** @var array{0:int,1:int,2:int} $result */
            $result = $pool->withConnection(function (ClientInterface $redis) use ($ip, $leaseId, $ttl): array {
                $perIpKey = self::PER_IP_KEY_PREFIX . $ip;
                $perIpCount = $ip !== '' ? (int) $redis->scard($perIpKey) : 0;
                $globalCount = (int) $redis->scard(self::GLOBAL_KEY);
                return [$perIpCount, $globalCount, 0];
            });
        } catch (\Throwable) {
            // Redis unreachable → degrade to in-memory limiter for
            // this worker. The documented contract: production
            // deployments treat Redis as a hard dependency; reaching
            // this branch means something is already broken.
            return $this->fallback()->claim($context);
        }

        [$perIpCount, $globalCount] = $result;

        if ($ip !== '' && $perIpCount >= $maxPerIp) {
            throw new UiSseConnectionLimitException(
                'sse_connection_limit_exceeded',
                'SSE connection limit reached for this client.',
            );
        }
        if ($globalCount >= $maxGlobal) {
            throw new UiSseConnectionLimitException(
                'sse_connection_limit_exceeded',
                'SSE connection limit reached.',
            );
        }

        try {
            $pool->withConnection(function (ClientInterface $redis) use ($ip, $leaseId, $ttl): void {
                if ($ip !== '') {
                    $perIpKey = self::PER_IP_KEY_PREFIX . $ip;
                    $redis->sadd($perIpKey, [$leaseId]);
                    $redis->expire($perIpKey, $ttl);
                }
                $redis->sadd(self::GLOBAL_KEY, [$leaseId]);
                $redis->expire(self::GLOBAL_KEY, $ttl);
            });
        } catch (\Throwable) {
            // If SADD fails after the cap-check passed, we admit the
            // connection without tracking — the stream's max-age cap
            // and the natural ctx TTL bound the damage. Log and move
            // on.
            if (class_exists(\Semitexa\Core\Log\StaticLoggerBridge::class)) {
                \Semitexa\Core\Log\StaticLoggerBridge::error('platform-ui', 'Redis SSE lease claim write failed', [
                    'ip' => $ip,
                    'channel' => $context->channelId,
                ]);
            }
        }

        return new UiSseConnectionLease(
            id: $leaseId,
            ip: $ip,
            channelId: $context->channelId,
            issuedAt: time(),
            ttlSeconds: $ttl,
        );
    }

    public function release(UiSseConnectionLease $lease): void
    {
        $pool = $this->resolvePool();
        if ($pool === null) {
            $this->fallback()->release($lease);
            return;
        }

        try {
            $pool->withConnection(static function (ClientInterface $redis) use ($lease): void {
                if ($lease->ip !== '') {
                    $redis->srem(self::PER_IP_KEY_PREFIX . $lease->ip, $lease->id);
                }
                $redis->srem(self::GLOBAL_KEY, $lease->id);
            });
        } catch (\Throwable) {
            // Best-effort. TTL eviction will catch the leak.
            if (class_exists(\Semitexa\Core\Log\StaticLoggerBridge::class)) {
                \Semitexa\Core\Log\StaticLoggerBridge::error('platform-ui', 'Redis SSE lease release failed', [
                    'lease' => $lease->id,
                ]);
            }
        }
    }

    /**
     * Test seam: inject a specific RedisConnectionPool.
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

    private function fallback(): InMemoryUiSseConnectionLimiter
    {
        if ($this->inMemoryFallback === null) {
            $this->inMemoryFallback = new InMemoryUiSseConnectionLimiter();
            $this->inMemoryFallback->maxPerIp = $this->resolveMaxPerIp();
            $this->inMemoryFallback->maxGlobal = $this->resolveMaxGlobal();
            $this->inMemoryFallback->leaseTtlSeconds = $this->resolveLeaseTtlSeconds();
        }
        return $this->inMemoryFallback;
    }

    private function resolveMaxPerIp(): int
    {
        return $this->envInt('SSE_MAX_CONN_PER_IP', self::DEFAULT_MAX_PER_IP);
    }

    private function resolveMaxGlobal(): int
    {
        return $this->envInt('SSE_MAX_CONN_GLOBAL', self::DEFAULT_MAX_GLOBAL);
    }

    private function resolveLeaseTtlSeconds(): int
    {
        $streamMaxAge = $this->envInt('SSE_MAX_CONNECTION_AGE_SECONDS', self::DEFAULT_STREAM_MAX_AGE_SECONDS);
        // TTL = stream-max-age + 100 second buffer so a normal close
        // releases first, but a missed release still gets evicted.
        return max(self::DEFAULT_LEASE_TTL_SECONDS, $streamMaxAge + 100);
    }

    private function envInt(string $key, int $default): int
    {
        $value = Environment::getEnvValue($key);
        if ($value === null || $value === '') {
            return $default;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        return is_int($parsed) && $parsed >= 0 ? $parsed : $default;
    }
}
