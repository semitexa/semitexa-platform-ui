<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit;

use Semitexa\Cache\Domain\Contract\CacheManagerInterface;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Environment;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitCsrfTokenHandle;

/**
 * Production CSRF token store — namespaced cache write of
 * `hash_hmac('sha256', raw, id)` against the token id, with TTL
 * bounded by the form's signed-ctx lifetime.
 *
 * Mirrors {@see \Semitexa\PlatformUi\Application\Service\Event\CacheBackedUiReplayStore}
 * verbatim — same property injection, same namespaced-cache memoisation,
 * Consumption is atomic: the token is compared first, so a wrong guess
 * spends nothing, then taken with CacheManagerInterface::take(), which
 * hands the value to one caller only. Before, two workers holding the
 * same valid token could both read it before either forgot it, and both
 * ran the action (review ultimate#130, tk-csrf-consume-atomic).
 *
 * Trust perimeter:
 *
 *   - The cache stores ONLY the HMAC hash. A leaked cache snapshot
 *     cannot replay a token because the raw value is not present and
 *     the HMAC keys each hash with the token id (so two tokens with
 *     the same raw bytes — astronomically unlikely — produce
 *     different stored hashes).
 *   - `consume()` returns the same `false` value for missing, expired,
 *     and mismatched cases; no side-channel hint.
 *   - `diagnosticName()` never surfaces secrets / cache backend
 *     credentials.
 */
#[SatisfiesServiceContract(of: UiFormSubmitCsrfTokenStoreInterface::class)]
final class CacheBackedUiFormSubmitCsrfTokenStore implements UiFormSubmitCsrfTokenStoreInterface
{
    public const NAMESPACE = 'ui-form-submit-csrf';
    public const ID_PREFIX = 'uicsrf_';

    #[InjectAsReadonly]
    protected CacheManagerInterface $cacheManager;

    private ?CacheManagerInterface $namespacedCache = null;

    /**
     * Test seam — production path uses property injection.
     */
    public function withCacheManager(CacheManagerInterface $cacheManager): self
    {
        $this->cacheManager = $cacheManager;
        $this->namespacedCache = null;
        return $this;
    }

    public function issue(int $ttlSeconds): UiFormSubmitCsrfTokenHandle
    {
        $ttl = $ttlSeconds < 1 ? 1 : $ttlSeconds;
        $id = self::ID_PREFIX . bin2hex(random_bytes(8));
        $raw = bin2hex(random_bytes(16));
        $this->namespacedCache()->put(
            $id,
            self::hash($id, $raw),
            $ttl,
        );
        return new UiFormSubmitCsrfTokenHandle(id: $id, raw: $raw);
    }

    public function consume(string $tokenId, string $rawToken): bool
    {
        $cache = $this->namespacedCache();
        $stored = $cache->get($tokenId);
        if (!is_string($stored) || $stored === '') {
            return false;
        }
        $expected = self::hash($tokenId, $rawToken);
        if (!hash_equals($stored, $expected)) {
            return false;
        }

        // Only the caller whose take removed it consumed it; a concurrent one gets null.
        $taken = $cache->take($tokenId);

        return is_string($taken) && hash_equals($taken, $expected);
    }

    private static function hash(string $tokenId, string $rawToken): string
    {
        // HMAC with the token id as key binds the stored value to a
        // specific id, so any cache entry leak cannot be replayed
        // against a different id.
        return hash_hmac('sha256', $rawToken, $tokenId);
    }

    /**
     * Same conservative shared-driver list as the replay store.
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
