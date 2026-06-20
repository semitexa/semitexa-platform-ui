<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Collaboration;

use Semitexa\Cache\Domain\Contract\CacheManagerInterface;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\PlatformUi\Domain\Contract\FormPresenceStoreInterface;
use Semitexa\PlatformUi\Domain\Model\Collaboration\FormPresenceParticipant;

/**
 * Cache-backed presence roster — the production default for
 * {@see FormPresenceStoreInterface}. Mirrors {@see \Semitexa\PlatformUi\Application\Service\Event\CacheBackedUiReplayStore}:
 * `CacheManagerInterface` via `#[InjectAsReadonly]`, a dedicated namespace, a
 * `withCacheManager()` test seam.
 *
 * One cache entry per document scope holds a `participantId → {label, role, ts}`
 * map. Every read prunes entries older than {@see PRESENCE_TTL_SECONDS}, and
 * every write re-puts the whole map under that TTL so the entry self-expires
 * once the last participant stops heartbeating.
 *
 * Concurrency caveat (shared with the replay store): the map is read-modify-
 * written, so two simultaneous pings on the SAME scope can race and one update
 * may be lost. Presence is advisory and self-healing — the next heartbeat
 * (seconds later) re-asserts the dropped participant — so the bounded window is
 * acceptable for v1; a native per-field atomic op is a future hardening.
 */
#[SatisfiesServiceContract(of: FormPresenceStoreInterface::class)]
final class CacheBackedFormPresenceStore implements FormPresenceStoreInterface
{
    public const NAMESPACE = 'form-collab-presence';
    public const PRESENCE_TTL_SECONDS = 30;

    #[InjectAsReadonly]
    protected CacheManagerInterface $cacheManager;

    private ?CacheManagerInterface $namespacedCache = null;

    /** Test seam — production path uses property injection. */
    public function withCacheManager(CacheManagerInterface $cacheManager): self
    {
        $this->cacheManager = $cacheManager;
        $this->namespacedCache = null;
        return $this;
    }

    public function ping(string $scopeKey, string $participantId, string $label, string $role): array
    {
        $map = $this->prune($this->read($scopeKey));
        $map[$participantId] = ['label' => $label, 'role' => $role, 'ts' => time()];
        $this->write($scopeKey, $map);

        return self::toRoster($map);
    }

    public function leave(string $scopeKey, string $participantId): array
    {
        $map = $this->prune($this->read($scopeKey));
        unset($map[$participantId]);
        $this->write($scopeKey, $map);

        return self::toRoster($map);
    }

    public function roster(string $scopeKey): array
    {
        $map = $this->prune($this->read($scopeKey));
        // Persist the prune so stale entries do not linger until the next write.
        $this->write($scopeKey, $map);

        return self::toRoster($map);
    }

    /** @return array<string, array{label:string,role:string,ts:int}> */
    private function read(string $scopeKey): array
    {
        $raw = $this->namespacedCache()->get($scopeKey);

        return is_array($raw) ? $raw : [];
    }

    /** @param array<string, array{label:string,role:string,ts:int}> $map */
    private function write(string $scopeKey, array $map): void
    {
        $this->namespacedCache()->put($scopeKey, $map, self::PRESENCE_TTL_SECONDS);
    }

    /**
     * @param array<string, array{label:string,role:string,ts:int}> $map
     * @return array<string, array{label:string,role:string,ts:int}>
     */
    private function prune(array $map): array
    {
        $cutoff = time() - self::PRESENCE_TTL_SECONDS;
        foreach ($map as $id => $entry) {
            if (!is_array($entry) || (int) ($entry['ts'] ?? 0) < $cutoff) {
                unset($map[$id]);
            }
        }

        return $map;
    }

    /**
     * @param array<string, array{label:string,role:string,ts:int}> $map
     * @return list<FormPresenceParticipant>
     */
    private static function toRoster(array $map): array
    {
        $roster = [];
        foreach ($map as $id => $entry) {
            $roster[] = new FormPresenceParticipant(
                participantId: (string) $id,
                label:         (string) ($entry['label'] ?? ''),
                role:          (string) ($entry['role'] ?? ''),
                lastSeenAt:    (int) ($entry['ts'] ?? 0),
            );
        }

        return $roster;
    }

    private function namespacedCache(): CacheManagerInterface
    {
        return $this->namespacedCache ??= $this->cacheManager->withNamespace(self::NAMESPACE);
    }
}
