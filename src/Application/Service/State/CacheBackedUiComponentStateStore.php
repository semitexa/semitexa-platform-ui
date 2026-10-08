<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\State;

use Semitexa\Cache\Domain\Contract\CacheManagerInterface;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Environment;

/**
 * Component state in the application cache, under its own namespace. Shared
 * across workers when the cache is (redis, valkey, memcached).
 */
#[SatisfiesServiceContract(of: UiComponentStateStoreInterface::class)]
final class CacheBackedUiComponentStateStore implements UiComponentStateStoreInterface
{
    public const NAMESPACE = 'ui-component-state';

    private const SHARED_DRIVERS = ['redis' => true, 'valkey' => true, 'memcached' => true];

    #[InjectAsReadonly]
    protected CacheManagerInterface $cacheManager;

    private ?CacheManagerInterface $namespaced = null;

    public function get(string $key): ?array
    {
        $value = $this->cache()->get($key);

        return is_array($value) ? $value : null;
    }

    public function put(string $key, array $props, int $ttlSeconds): void
    {
        $this->cache()->put($key, $props, max(1, $ttlSeconds));
    }

    public function isShared(): bool
    {
        return isset(self::SHARED_DRIVERS[strtolower(trim((string) Environment::getEnvValue('CACHE_DRIVER', 'array')))]);
    }

    private function cache(): CacheManagerInterface
    {
        return $this->namespaced ??= $this->cacheManager->withNamespace(self::NAMESPACE);
    }
}
