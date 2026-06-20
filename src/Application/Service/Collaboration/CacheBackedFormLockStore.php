<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Collaboration;

use Semitexa\Cache\Domain\Contract\CacheManagerInterface;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\PlatformUi\Domain\Contract\FormLockStoreInterface;
use Semitexa\PlatformUi\Domain\Model\Collaboration\FormLock;
use Semitexa\PlatformUi\Domain\Model\Collaboration\FormLockOutcome;

/**
 * Cache-backed lock store — the production default for
 * {@see FormLockStoreInterface}. One cache entry per lock slot
 * (`{scopeKey}` for the whole-form lock, `{scopeKey}#field:{field}` for a
 * per-field lock) holds `{holderId, label, ts}` under {@see LOCK_TTL_SECONDS}.
 *
 * Acquire is non-forcing: it grants the lock only when the slot is empty (the
 * cache entry expired or was released) or already held by the same caller.
 * Takeover is implicit and TTL-driven — once a holder stops {@see heartbeat()}ing,
 * its entry expires and the next acquirer wins. Backed by the shared cache
 * (Redis) for cross-worker consistency.
 *
 * Concurrency caveat (shared with the replay store): acquire is a read-then-put,
 * so two acquires on the same free slot in the same instant could both observe
 * "free". The exposure is a sub-millisecond window per slot; for v1 the lock is
 * an advisory coordination aid layered over the optimistic version guard (which
 * still catches a genuine double write), not a hard mutex. A native atomic
 * SETNX claim is the future hardening.
 */
#[SatisfiesServiceContract(of: FormLockStoreInterface::class)]
final class CacheBackedFormLockStore implements FormLockStoreInterface
{
    public const NAMESPACE = 'form-collab-lock';
    public const LOCK_TTL_SECONDS = 30;

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

    public function acquire(string $scopeKey, ?string $field, string $holderId, string $holderLabel): FormLockOutcome
    {
        $entry = $this->read($scopeKey, $field);

        if ($entry !== null && (string) $entry['holderId'] !== $holderId) {
            // Held by someone else and still live → denied; report the incumbent.
            return new FormLockOutcome(false, self::toLock($scopeKey, $field, $entry));
        }

        // Free, or a re-affirm by the same holder → (re)grant and refresh TTL.
        // Preserve the original acquiredAt on a re-affirm.
        $acquiredAt = $entry['ts'] ?? time();
        $stored = ['holderId' => $holderId, 'label' => $holderLabel, 'ts' => $acquiredAt];
        $this->write($scopeKey, $field, $stored);

        return new FormLockOutcome(true, self::toLock($scopeKey, $field, $stored));
    }

    public function heartbeat(string $scopeKey, ?string $field, string $holderId): bool
    {
        $entry = $this->read($scopeKey, $field);
        if ($entry === null || (string) $entry['holderId'] !== $holderId) {
            return false;
        }
        // Refresh TTL, keep acquiredAt.
        $this->write($scopeKey, $field, $entry);

        return true;
    }

    public function release(string $scopeKey, ?string $field, string $holderId): void
    {
        $entry = $this->read($scopeKey, $field);
        if ($entry !== null && (string) $entry['holderId'] === $holderId) {
            $this->namespacedCache()->forget(self::slot($scopeKey, $field));
        }
    }

    public function current(string $scopeKey, ?string $field): ?FormLock
    {
        $entry = $this->read($scopeKey, $field);

        return $entry === null ? null : self::toLock($scopeKey, $field, $entry);
    }

    /** @return array{holderId:string,label:string,ts:int}|null */
    private function read(string $scopeKey, ?string $field): ?array
    {
        $raw = $this->namespacedCache()->get(self::slot($scopeKey, $field));

        return is_array($raw) && isset($raw['holderId']) ? $raw : null;
    }

    /** @param array{holderId:string,label:string,ts:int} $entry */
    private function write(string $scopeKey, ?string $field, array $entry): void
    {
        $this->namespacedCache()->put(self::slot($scopeKey, $field), $entry, self::LOCK_TTL_SECONDS);
    }

    private static function slot(string $scopeKey, ?string $field): string
    {
        return $field === null ? $scopeKey : $scopeKey . '#field:' . $field;
    }

    /** @param array{holderId:string,label:string,ts:int} $entry */
    private static function toLock(string $scopeKey, ?string $field, array $entry): FormLock
    {
        return new FormLock(
            scopeKey:    $scopeKey,
            field:       $field,
            holderId:    (string) $entry['holderId'],
            holderLabel: (string) ($entry['label'] ?? ''),
            acquiredAt:  (int) ($entry['ts'] ?? 0),
        );
    }

    private function namespacedCache(): CacheManagerInterface
    {
        return $this->namespacedCache ??= $this->cacheManager->withNamespace(self::NAMESPACE);
    }
}
