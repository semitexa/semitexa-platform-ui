<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

use Semitexa\PlatformUi\Domain\Exception\UiSseConnectionLimitException;

/**
 * Worker-local connection limiter. NOT safe across multiple Swoole
 * workers / PHP processes — used by tests and by the handler's
 * defensive fallback when no Redis pool is available.
 *
 * Apps and modules MUST NOT bind this with SatisfiesServiceContract;
 * production should resolve to RedisUiSseConnectionLimiter.
 */
final class InMemoryUiSseConnectionLimiter implements UiSseConnectionLimiterInterface
{
    /** @var array<string, list<array{id:string, expiresAt:int}>> ip → leases */
    private array $perIp = [];
    /** @var list<array{id:string, expiresAt:int}> */
    private array $global = [];

    public int $maxPerIp = 5;
    public int $maxGlobal = 500;
    public int $leaseTtlSeconds = 700; // > MAX_AGE so a normal close releases first

    public function claim(UiSseSubscriptionContext $context): UiSseConnectionLease
    {
        $this->purgeExpired();

        $ip = $context->requestIp;
        $perIpCount = $ip !== '' ? count($this->perIp[$ip] ?? []) : 0;
        if ($ip !== '' && $perIpCount >= $this->maxPerIp) {
            throw new UiSseConnectionLimitException(
                'sse_connection_limit_exceeded',
                'SSE connection limit reached for this client.',
            );
        }
        if (count($this->global) >= $this->maxGlobal) {
            throw new UiSseConnectionLimitException(
                'sse_connection_limit_exceeded',
                'SSE connection limit reached.',
            );
        }

        $now = time();
        $lease = new UiSseConnectionLease(
            id: 'uscl_' . bin2hex(random_bytes(16)),
            ip: $ip,
            channelId: $context->channelId,
            issuedAt: $now,
            ttlSeconds: $this->leaseTtlSeconds,
        );
        $entry = ['id' => $lease->id, 'expiresAt' => $now + $this->leaseTtlSeconds];
        if ($ip !== '') {
            $this->perIp[$ip][] = $entry;
        }
        $this->global[] = $entry;
        return $lease;
    }

    public function release(UiSseConnectionLease $lease): void
    {
        if ($lease->ip !== '' && isset($this->perIp[$lease->ip])) {
            $this->perIp[$lease->ip] = array_values(array_filter(
                $this->perIp[$lease->ip],
                static fn (array $e): bool => $e['id'] !== $lease->id,
            ));
            if ($this->perIp[$lease->ip] === []) {
                unset($this->perIp[$lease->ip]);
            }
        }
        $this->global = array_values(array_filter(
            $this->global,
            static fn (array $e): bool => $e['id'] !== $lease->id,
        ));
    }

    /** Test reset hook. */
    public function reset(): void
    {
        $this->perIp = [];
        $this->global = [];
    }

    private function purgeExpired(): void
    {
        $now = time();
        foreach ($this->perIp as $ip => $entries) {
            $this->perIp[$ip] = array_values(array_filter(
                $entries,
                static fn (array $e): bool => $e['expiresAt'] > $now,
            ));
            if ($this->perIp[$ip] === []) {
                unset($this->perIp[$ip]);
            }
        }
        $this->global = array_values(array_filter(
            $this->global,
            static fn (array $e): bool => $e['expiresAt'] > $now,
        ));
    }
}
