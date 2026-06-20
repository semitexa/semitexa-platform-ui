<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Support;

use Semitexa\Cache\Domain\Contract\CacheManagerInterface;
use Semitexa\Cache\Domain\Enum\CacheScope;

/**
 * Array-backed CacheManager test double for the collaboration stores: a real
 * get/put/forget/withNamespace surface (TTL ignored — logic-level tests). The
 * methods the stores never call throw, to keep the exercised surface honest.
 *
 * Shared fixture (PSR-4 autoloaded under Semitexa\PlatformUi\Tests\) so both the
 * presence/lock store tests and the inbound-handler test drive the same fake.
 */
final class ArrayCacheManager implements CacheManagerInterface
{
    /** @var array<string, array<string, mixed>> namespace → key → value */
    public array $store = [];
    private string $ns = '';

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store[$this->ns][$key] ?? $default;
    }

    public function put(string $key, mixed $value, ?int $ttlSeconds = null, array $tags = []): void
    {
        $this->store[$this->ns][$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($this->store[$this->ns][$key]);
    }

    public function withNamespace(string $namespace): CacheManagerInterface
    {
        $clone = clone $this;
        $clone->ns = $namespace;

        return $clone;
    }

    public function remember(string $key, callable $resolver, ?int $ttlSeconds = null, array $tags = []): mixed
    {
        throw new \LogicException('remember() should not be reached.');
    }

    public function flushTags(string ...$tags): int
    {
        throw new \LogicException('flushTags() should not be reached.');
    }

    public function flushNamespace(?string $namespace = null): int
    {
        throw new \LogicException('flushNamespace() should not be reached.');
    }

    public function withTags(string ...$tags): CacheManagerInterface
    {
        throw new \LogicException('withTags() should not be reached.');
    }

    public function scope(CacheScope $scope): CacheManagerInterface
    {
        throw new \LogicException('scope() should not be reached.');
    }
}
