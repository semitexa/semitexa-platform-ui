<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Collaboration;

use Semitexa\Cache\Domain\Contract\CacheManagerInterface;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;

/**
 * "The record of this document was saved": the notice the open editors of a
 * collaborative form get once, after the app persisted the record and ended
 * the draft ({@see FormCollaborationDrafts}).
 *
 * It is NOT a draft. Seeding a new draft with the persisted values left a
 * live copy of the record that the next save elsewhere made stale: the very
 * overlay the discard removed. A notice holds the values only for editors who
 * had the form open when the save happened. A page rendered after the save
 * already shows the record: its manifest carries the id of the notice current
 * at render time, and the runtime never treats that one as news. An id, not a
 * clock: the page a saver is redirected to is rendered in the same second as
 * her save.
 *
 * Its id is also what tells one save from the next when no draft exists on
 * either side of it (a draft created and discarded between two frames).
 *
 * Kept a day in the SHARED cache, like presence: with a per-worker array cache
 * a notice reaches only the editors served by the worker that saved. A notice that is lost (a
 * flushed cache) costs the re-base, not correctness: the editors see their
 * draft end and are asked to reload.
 */
#[AsService]
final class FormCollabSaveNotices
{
    public const NAMESPACE = 'form-collab-saved';
    public const TTL_SECONDS = 86400;

    #[InjectAsReadonly]
    protected CacheManagerInterface $cacheManager;

    private ?CacheManagerInterface $namespacedCache = null;

    private static ?self $active = null;

    /**
     * The container's instance, for the `ui_collab_manifest` Twig helper,
     * which is built by reflection and has no injection (the pattern of
     * UiFieldRuleRegistry::setActive()). Set at worker start.
     */
    public static function setActive(?self $notices): void
    {
        self::$active = $notices;
    }

    public static function active(): ?self
    {
        return self::$active;
    }

    /** Test seam — production path uses property injection. */
    public function withCacheManager(CacheManagerInterface $cacheManager): self
    {
        $this->cacheManager = $cacheManager;
        $this->namespacedCache = null;
        return $this;
    }

    /**
     * Record that the document's record was saved now.
     *
     * @param array<string, scalar|null>|null $values the persisted value of every managed field, or null when the app gave none
     * @return array{id: string, at: int, values: array<string, scalar|null>|null}
     */
    public function publish(string $scopeKey, ?array $values): array
    {
        $notice = ['id' => bin2hex(random_bytes(8)), 'at' => time(), 'values' => $values === null ? null : self::clean($values)];
        $this->namespacedCache()->put($scopeKey, $notice, self::TTL_SECONDS);

        return $notice;
    }

    /**
     * The latest notice of a document, or null (none in the last day, or the
     * cache does not say).
     *
     * @return array{id: string, at: int, values: array<string, scalar|null>|null}|null
     */
    public function current(string $scopeKey): ?array
    {
        $raw = $this->namespacedCache()->get($scopeKey);
        if (!is_array($raw) || !is_string($raw['id'] ?? null) || $raw['id'] === '' || !is_int($raw['at'] ?? null)) {
            return null;
        }

        return ['id' => $raw['id'], 'at' => $raw['at'], 'values' => is_array($raw['values'] ?? null) ? self::clean($raw['values']) : null];
    }

    /**
     * @param array<mixed> $values
     * @return array<string, scalar|null>
     */
    private static function clean(array $values): array
    {
        $clean = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && (is_scalar($value) || $value === null)) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    private function namespacedCache(): CacheManagerInterface
    {
        return $this->namespacedCache ??= $this->cacheManager->withNamespace(self::NAMESPACE);
    }
}
