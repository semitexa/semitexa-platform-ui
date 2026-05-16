<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cache\Domain\Contract\CacheManagerInterface;
use Semitexa\Cache\Domain\Enum\CacheScope;
use Semitexa\PlatformUi\Application\Service\Event\CacheBackedUiReplayStore;

/**
 * Unit tests for the cache-backed replay store using a fake
 * CacheManagerInterface adapter. The adapter only implements what the
 * store actually exercises: get / put / withNamespace. Other contract
 * methods throw to keep the surface honest — if the store starts using
 * remember/forget/flushTags, those tests must be updated explicitly.
 */
final class CacheBackedUiReplayStoreTest extends TestCase
{
    private ?string $previousCacheDriver = null;

    protected function setUp(): void
    {
        $prev = getenv('CACHE_DRIVER');
        $this->previousCacheDriver = $prev === false ? null : $prev;
    }

    protected function tearDown(): void
    {
        if ($this->previousCacheDriver === null) {
            putenv('CACHE_DRIVER');
        } else {
            putenv('CACHE_DRIVER=' . $this->previousCacheDriver);
        }
    }

    private function newStore(): array
    {
        $cache = new FakeCacheManager();
        $store = (new CacheBackedUiReplayStore())->withCacheManager($cache);
        return [$store, $cache];
    }

    #[Test]
    public function first_claim_succeeds(): void
    {
        [$store, ] = $this->newStore();
        self::assertTrue($store->claim('k1', 60));
    }

    #[Test]
    public function duplicate_claim_within_ttl_fails(): void
    {
        [$store, ] = $this->newStore();
        self::assertTrue($store->claim('k1', 60));
        self::assertFalse($store->claim('k1', 60));
    }

    #[Test]
    public function distinct_keys_are_independent(): void
    {
        [$store, ] = $this->newStore();
        self::assertTrue($store->claim('a', 60));
        self::assertTrue($store->claim('b', 60));
        self::assertFalse($store->claim('a', 60));
        self::assertFalse($store->claim('b', 60));
    }

    #[Test]
    public function namespace_is_applied(): void
    {
        [$store, $cache] = $this->newStore();
        $store->claim('k1', 60);
        self::assertSame(CacheBackedUiReplayStore::NAMESPACE, $cache->state->lastNamespace);
        $first = $cache->state->scopedKeys[0];
        self::assertSame(CacheBackedUiReplayStore::NAMESPACE, $first[0]);
        self::assertSame('k1', $first[1]);
    }

    #[Test]
    public function ttl_is_passed_through_with_floor(): void
    {
        [$store, $cache] = $this->newStore();
        $store->claim('k1', 42);
        self::assertSame(42, $cache->state->lastPutTtl);

        // Zero or negative TTL is floored to 1 — the store must never
        // store a marker with a no-op TTL that would let the key live
        // forever, and must never call put() with ttl<1.
        $store->claim('k2', 0);
        self::assertSame(1, $cache->state->lastPutTtl);

        $store->claim('k3', -5);
        self::assertSame(1, $cache->state->lastPutTtl);
    }

    #[Test]
    public function namespace_constant_matches_documented_value(): void
    {
        // Regression: docs + Redis tooling depend on this string. Pin it.
        self::assertSame('ui-dispatch-replay', CacheBackedUiReplayStore::NAMESPACE);
    }

    #[Test]
    public function reports_shared_when_cache_driver_is_redis(): void
    {
        putenv('CACHE_DRIVER=redis');
        [$store, ] = $this->newStore();
        self::assertTrue($store->isShared());
        self::assertSame('cache-backed (driver=redis)', $store->diagnosticName());
    }

    #[Test]
    public function reports_shared_for_valkey_and_memcached(): void
    {
        // Valkey is the Redis-compatible OSS fork; memcached has the
        // same cross-process semantics for our purpose. Both must count
        // as shared so operators can swap drivers without code changes.
        foreach (['valkey', 'memcached'] as $driver) {
            putenv('CACHE_DRIVER=' . $driver);
            [$store, ] = $this->newStore();
            self::assertTrue($store->isShared(), "Driver '{$driver}' must be reported as shared.");
            self::assertSame('cache-backed (driver=' . $driver . ')', $store->diagnosticName());
        }
    }

    #[Test]
    public function reports_non_shared_when_cache_driver_is_array(): void
    {
        putenv('CACHE_DRIVER=array');
        [$store, ] = $this->newStore();
        self::assertFalse($store->isShared());
        self::assertSame('cache-backed (driver=array)', $store->diagnosticName());
    }

    #[Test]
    public function explicit_array_driver_is_reported_non_shared(): void
    {
        // The framework default for CACHE_DRIVER is 'array' (per
        // CacheConfig). Pin the failure-closed behaviour with the
        // explicit value — Environment::getEnvValue caches a parsed
        // .env per worker, so a bare putenv() unset is not enough to
        // simulate "no value" once that cache is warm.
        putenv('CACHE_DRIVER=array');
        [$store, ] = $this->newStore();
        self::assertFalse($store->isShared());
        self::assertSame('cache-backed (driver=array)', $store->diagnosticName());
    }

    #[Test]
    public function unknown_driver_reports_non_shared(): void
    {
        putenv('CACHE_DRIVER=mystery-driver-9000');
        [$store, ] = $this->newStore();
        self::assertFalse(
            $store->isShared(),
            'Unknown drivers must fail closed — production guard refuses to invoke handler.',
        );
    }

    #[Test]
    public function driver_value_is_case_insensitive(): void
    {
        putenv('CACHE_DRIVER=REDIS');
        [$store, ] = $this->newStore();
        self::assertTrue($store->isShared());
    }

    #[Test]
    public function withCacheManager_resets_namespaced_cache(): void
    {
        $store = new CacheBackedUiReplayStore();
        $cache1 = new FakeCacheManager();
        $store->withCacheManager($cache1);
        $store->claim('k1', 60);
        // One claim → one get + one put against the namespaced view.
        self::assertSame(2, $cache1->state->callCount);

        // Swap the cache: subsequent claims must go to the new instance
        // (and the new instance has its own scoped view).
        $cache2 = new FakeCacheManager();
        $store->withCacheManager($cache2);
        $store->claim('k1', 60);
        self::assertSame(2, $cache2->state->callCount);
    }
}

/**
 * Shared mutable state for a FakeCacheManager + all clones returned by
 * withNamespace(). Required so the production code (which writes to
 * the *namespaced clone*) and the test (which inspects the *root*)
 * observe the same recorded operations.
 */
final class FakeCacheState
{
    /** @var array<string, array<string, mixed>> namespace → key → value */
    public array $store = [];

    public string $lastNamespace = '';
    public ?int $lastPutTtl = null;
    /** @var list<array{0:string,1:string}> namespace+key pairs in call order */
    public array $scopedKeys = [];
    public int $callCount = 0;
}

/**
 * Test-only adapter for CacheManagerInterface. Records what the
 * production code actually exercises so the test asserts contract
 * intent, not just outcomes.
 *
 * State (counters + recorded calls) lives on a shared $state object so
 * the root cache and every namespaced clone reflect the same recording.
 */
final class FakeCacheManager implements CacheManagerInterface
{
    public FakeCacheState $state;
    private string $currentNamespace = '';

    public function __construct(?FakeCacheState $state = null)
    {
        $this->state = $state ?? new FakeCacheState();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->state->scopedKeys[] = [$this->currentNamespace, $key];
        $this->state->callCount++;
        return $this->state->store[$this->currentNamespace][$key] ?? $default;
    }

    public function put(string $key, mixed $value, ?int $ttlSeconds = null, array $tags = []): void
    {
        $this->state->scopedKeys[] = [$this->currentNamespace, $key];
        $this->state->callCount++;
        $this->state->store[$this->currentNamespace][$key] = $value;
        $this->state->lastPutTtl = $ttlSeconds;
    }

    public function withNamespace(string $namespace): CacheManagerInterface
    {
        $this->state->lastNamespace = $namespace;
        $clone = new self($this->state);
        $clone->currentNamespace = $namespace;
        return $clone;
    }

    public function remember(string $key, callable $resolver, ?int $ttlSeconds = null, array $tags = []): mixed
    {
        throw new \LogicException('remember() should not be reached by CacheBackedUiReplayStore.');
    }

    public function forget(string $key): void
    {
        throw new \LogicException('forget() should not be reached by CacheBackedUiReplayStore.');
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
