<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit;

use Semitexa\Cache\Domain\Contract\CacheManagerInterface;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Environment;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormDemoSubmissionRecord;

/**
 * Default demo submission repository — namespaced cache write of one
 * {@see UiFormDemoSubmissionRecord} per id with a fixed 24h TTL.
 *
 * Mirrors {@see CacheBackedUiReplayStore} / {@see CacheBackedUiFormSubmitCsrfTokenStore}
 * — same property injection, same namespaced-cache memoisation, same
 * conservative `isShared()` driver list. The cache namespace
 * (`ui-form-demo-submissions`) is dedicated so demo records cannot
 * collide with any other cache user.
 *
 * Demo-grade ceiling:
 *
 *   - 24h TTL — long enough to be useful for ops sanity-checks
 *     ("did anything land in the demo store today?") but short
 *     enough that abandoned demo deployments do not accumulate
 *     unbounded data.
 *   - Records are stored as plain arrays (the record's public
 *     readonly properties); JSON serialisation roundtrips cleanly
 *     and the cache backend handles its own encoding.
 */
#[SatisfiesServiceContract(of: UiFormDemoSubmissionRepositoryInterface::class)]
final class CacheBackedUiFormDemoSubmissionRepository implements UiFormDemoSubmissionRepositoryInterface
{
    public const NAMESPACE = 'ui-form-demo-submissions';
    public const TTL_SECONDS = 86400;
    public const ID_PREFIX = 'uifs_';

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

    public function save(UiFormDemoSubmissionRecord $record): string
    {
        $this->namespacedCache()->put(
            $record->id,
            self::encode($record),
            self::TTL_SECONDS,
        );
        return $record->id;
    }

    public function find(string $id): ?UiFormDemoSubmissionRecord
    {
        $payload = $this->namespacedCache()->get($id);
        if (!is_array($payload)) {
            return null;
        }
        return self::decode($payload);
    }

    /**
     * @return array{id: string, formInstanceId: string, actionName: string, submittedAt: int, values: array<string, scalar|null>}
     */
    private static function encode(UiFormDemoSubmissionRecord $record): array
    {
        return [
            'id'             => $record->id,
            'formInstanceId' => $record->formInstanceId,
            'actionName'     => $record->actionName,
            'submittedAt'    => $record->submittedAt,
            'values'         => $record->values,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function decode(array $payload): ?UiFormDemoSubmissionRecord
    {
        $id = $payload['id'] ?? null;
        $formInstanceId = $payload['formInstanceId'] ?? null;
        $actionName = $payload['actionName'] ?? null;
        $submittedAt = $payload['submittedAt'] ?? null;
        $values = $payload['values'] ?? null;
        if (
            !is_string($id) || !is_string($formInstanceId) || !is_string($actionName)
            || !is_int($submittedAt) || !is_array($values)
        ) {
            return null;
        }
        $normalised = [];
        foreach ($values as $key => $value) {
            if (!is_string($key) || (!is_scalar($value) && $value !== null)) {
                return null;
            }
            $normalised[$key] = $value;
        }
        return new UiFormDemoSubmissionRecord(
            id: $id,
            formInstanceId: $formInstanceId,
            actionName: $actionName,
            submittedAt: $submittedAt,
            values: $normalised,
        );
    }

    /**
     * Same conservative shared-driver list as the replay + CSRF stores.
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
