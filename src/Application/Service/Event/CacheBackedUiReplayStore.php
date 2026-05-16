<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

use Semitexa\Cache\Domain\Contract\CacheManagerInterface;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Environment;

/**
 * Cache-backed replay store for production deployments.
 *
 * Delegates to Semitexa's CacheManagerInterface inside a dedicated
 * namespace ("ui-dispatch-replay") so dispatch-replay entries cannot
 * collide with any other cache user. TTL is honoured per-entry.
 *
 * Default binding: this class is registered as the default
 * implementation of UiReplayStoreInterface via SatisfiesServiceContract.
 * Production deployments inherit the cache layer's process-shared
 * semantics (Redis/file/etc, whatever CacheManagerInterface resolves
 * to in the app). Apps that need a different store (e.g. an MySQL
 * replay table) bind their own implementation in a higher-priority
 * module; the SatisfiesServiceContract registry picks the
 * descendant-module winner.
 *
 * DI shape: container-managed services in Semitexa receive their
 * dependencies through protected property injection, not constructor
 * args — the framework forbids constructor injection on these classes.
 * The raw CacheManagerInterface is therefore injected as a property and
 * the namespaced accessor is memoized lazily.
 *
 * Atomicity caveat:
 *   The underlying CacheManagerInterface does not currently expose a
 *   native SETNX/`add` primitive in the public contract. This
 *   implementation uses a get-then-put pattern, which is correct under
 *   normal traffic but is technically vulnerable to a tiny race window
 *   if two concurrent workers both observe "absent" before either
 *   writes the marker. A native atomic claim (e.g. Redis SETNX with PX)
 *   should land alongside the future "real anti-abuse" hardening — for
 *   the current "duplicate dispatch protection" goal, the practical
 *   exposure is bounded by the dispatchId entropy (128 bits, generated
 *   per attempt) and the signed-ctx TTL.
 */
#[SatisfiesServiceContract(of: UiReplayStoreInterface::class)]
final class CacheBackedUiReplayStore implements UiReplayStoreInterface
{
    public const NAMESPACE = 'ui-dispatch-replay';

    #[InjectAsReadonly]
    protected CacheManagerInterface $cacheManager;

    private ?CacheManagerInterface $namespacedCache = null;

    /**
     * Test seam — production path uses property injection. Tests
     * construct the store with a fake CacheManagerInterface so each
     * test gets a clean cache view without bootstrapping the container.
     */
    public function withCacheManager(CacheManagerInterface $cacheManager): self
    {
        $this->cacheManager = $cacheManager;
        $this->namespacedCache = null;
        return $this;
    }

    public function claim(string $key, int $ttlSeconds): bool
    {
        $cache = $this->namespacedCache();
        if ($cache->get($key) !== null) {
            return false;
        }
        $ttl = $ttlSeconds < 1 ? 1 : $ttlSeconds;
        $cache->put($key, 1, $ttl);
        return true;
    }

    /**
     * Drivers we trust to share state across Swoole workers / PHP
     * processes. `array` is process-local; everything else here is
     * either process-shared (redis, valkey, memcached) or
     * filesystem-shared with the same caveats SETNX-less stores carry.
     *
     * The list is conservative: an unknown driver is reported as
     * non-shared so the runtime guard fails closed rather than open.
     *
     * @var array<string, true>
     */
    private const SHARED_DRIVERS = [
        'redis' => true,
        'valkey' => true,
        'memcached' => true,
    ];

    public function isShared(): bool
    {
        return isset(self::SHARED_DRIVERS[$this->driver()]);
    }

    public function diagnosticName(): string
    {
        $driver = $this->driver();
        return 'cache-backed (driver=' . ($driver !== '' ? $driver : 'unknown') . ')';
    }

    private function driver(): string
    {
        // Read the same env knob CacheConfig::fromEnvironment() reads,
        // so isShared() reflects what the bound CacheManager actually
        // uses. Done at the env boundary so we don't have to touch
        // CacheManagerInterface's contract.
        $value = Environment::getEnvValue('CACHE_DRIVER', 'array');
        return strtolower(trim((string) $value));
    }

    private function namespacedCache(): CacheManagerInterface
    {
        if ($this->namespacedCache === null) {
            $this->namespacedCache = $this->cacheManager->withNamespace(self::NAMESPACE);
        }
        return $this->namespacedCache;
    }
}
