<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Event\InMemoryUiSseConnectionLimiter;
use Semitexa\PlatformUi\Application\Service\Event\UiSseSubscriptionContext;
use Semitexa\PlatformUi\Domain\Exception\UiSseConnectionLimitException;

final class InMemoryUiSseConnectionLimiterTest extends TestCase
{
    private function newLimiter(int $perIp = 2, int $global = 4, int $ttl = 60): InMemoryUiSseConnectionLimiter
    {
        $limiter = new InMemoryUiSseConnectionLimiter();
        $limiter->maxPerIp = $perIp;
        $limiter->maxGlobal = $global;
        $limiter->leaseTtlSeconds = $ttl;
        return $limiter;
    }

    private function ctx(string $channelId = 'uch_test_0001', string $ip = '127.0.0.1'): UiSseSubscriptionContext
    {
        return new UiSseSubscriptionContext(
            channelId: $channelId,
            purpose:   'ui-patch-stream',
            issuedAt:  time(),
            expiresAt: time() + 60,
            requestIp: $ip,
        );
    }

    #[Test]
    public function first_claim_succeeds_and_returns_lease(): void
    {
        $limiter = $this->newLimiter();
        $lease = $limiter->claim($this->ctx());
        self::assertSame('127.0.0.1', $lease->ip);
        self::assertSame('uch_test_0001', $lease->channelId);
        self::assertMatchesRegularExpression('/\Auscl_[0-9a-f]{32}\z/', $lease->id);
    }

    #[Test]
    public function rejects_over_per_ip_limit(): void
    {
        $limiter = $this->newLimiter(perIp: 2, global: 10);
        $limiter->claim($this->ctx(ip: '10.0.0.1'));
        $limiter->claim($this->ctx(ip: '10.0.0.1'));
        $this->expectException(UiSseConnectionLimitException::class);
        try {
            $limiter->claim($this->ctx(ip: '10.0.0.1'));
        } catch (UiSseConnectionLimitException $e) {
            self::assertSame(429, $e->httpStatus);
            self::assertSame('sse_connection_limit_exceeded', $e->reason);
            throw $e;
        }
    }

    #[Test]
    public function distinct_ips_have_independent_per_ip_buckets(): void
    {
        $limiter = $this->newLimiter(perIp: 1, global: 10);
        $a = $limiter->claim($this->ctx(ip: '10.0.0.1'));
        $b = $limiter->claim($this->ctx(ip: '10.0.0.2'));
        self::assertSame('10.0.0.1', $a->ip);
        self::assertSame('10.0.0.2', $b->ip);
    }

    #[Test]
    public function rejects_over_global_limit(): void
    {
        $limiter = $this->newLimiter(perIp: 10, global: 2);
        $limiter->claim($this->ctx(ip: '10.0.0.1'));
        $limiter->claim($this->ctx(ip: '10.0.0.2'));
        $this->expectException(UiSseConnectionLimitException::class);
        $limiter->claim($this->ctx(ip: '10.0.0.3'));
    }

    #[Test]
    public function release_frees_a_slot(): void
    {
        $limiter = $this->newLimiter(perIp: 1, global: 10);
        $lease = $limiter->claim($this->ctx(ip: '10.0.0.1'));
        $limiter->release($lease);
        // Second claim from same IP must now succeed.
        $second = $limiter->claim($this->ctx(ip: '10.0.0.1'));
        self::assertSame('10.0.0.1', $second->ip);
    }

    #[Test]
    public function release_is_idempotent(): void
    {
        $limiter = $this->newLimiter();
        $lease = $limiter->claim($this->ctx());
        $limiter->release($lease);
        $limiter->release($lease); // second release must not throw.
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function empty_ip_does_not_count_against_per_ip_limit(): void
    {
        // When running behind a non-Swoole runtime that does not
        // expose remote_addr, requestIp is empty. We must not block
        // every connection because the IP bucket key would collide.
        $limiter = $this->newLimiter(perIp: 1, global: 10);
        $limiter->claim($this->ctx(ip: ''));
        $limiter->claim($this->ctx(ip: '')); // would fail if '' were treated as an IP
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function expired_leases_are_purged_on_subsequent_claim(): void
    {
        $limiter = $this->newLimiter(perIp: 1, global: 10, ttl: 1);
        $limiter->claim($this->ctx(ip: '10.0.0.1'));
        sleep(2);
        // The cap would have rejected without TTL purge.
        $second = $limiter->claim($this->ctx(ip: '10.0.0.1'));
        self::assertSame('10.0.0.1', $second->ip);
    }

    #[Test]
    public function safe_error_message_does_not_leak_class_or_counter(): void
    {
        $limiter = $this->newLimiter(perIp: 0, global: 10);
        try {
            $limiter->claim($this->ctx(ip: '10.0.0.1'));
            self::fail('Expected exception');
        } catch (UiSseConnectionLimitException $e) {
            self::assertStringNotContainsString('InMemoryUiSseConnectionLimiter', $e->getMessage());
            self::assertStringNotContainsString('Semitexa\\', $e->getMessage());
        }
    }
}
