<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Collaboration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cache\Domain\Contract\CacheManagerInterface;
use Semitexa\Cache\Domain\Enum\CacheScope;
use Semitexa\PlatformUi\Application\Service\Collaboration\CacheBackedFormLockStore;
use Semitexa\PlatformUi\Application\Service\Event\CacheBackedUiReplayStore;

/**
 * A lock is one holder's and a replay claim is accepted once, when another
 * worker acts between this one's read and its write (tk-cache-set-if-absent).
 * get() then put() granted one lock to two holders; a check then forget()
 * released a lock someone else had taken in between.
 */
final class FormLockInterleavingTest extends TestCase
{
    #[Test]
    public function two_holders_racing_for_a_free_lock_get_one_grant(): void
    {
        $cache = new InterleavingCache();
        $a = (new CacheBackedFormLockStore())->withCacheManager($cache);
        $b = (new CacheBackedFormLockStore())->withCacheManager($cache);
        // B acquires the moment A has read anything (the old acquire read the
        // slot, found it free, and wrote; B slipped in between and both won).
        $bOutcome = null;
        $cache->afterRead = static function () use ($b, &$bOutcome): void {
            $bOutcome = $b->acquire('form-7', 'title', 'holder-b', 'B');
        };

        $aOutcome = $a->acquire('form-7', 'title', 'holder-a', 'A');
        $bOutcome ??= $b->acquire('form-7', 'title', 'holder-b', 'B');

        self::assertSame(1, (int) $aOutcome->acquired + (int) $bOutcome->acquired, 'one lock, one holder');
        $winner = $aOutcome->acquired ? 'holder-a' : 'holder-b';
        self::assertSame($winner, $a->current('form-7', 'title')?->holderId);
        self::assertSame($winner, ($aOutcome->acquired ? $bOutcome : $aOutcome)->holder->holderId, 'the loser is told who holds it');
    }

    #[Test]
    public function a_release_keeps_a_lock_another_holder_took_in_between(): void
    {
        $cache = new InterleavingCache();
        $a = (new CacheBackedFormLockStore())->withCacheManager($cache);
        $b = (new CacheBackedFormLockStore())->withCacheManager($cache);
        self::assertTrue($a->acquire('form-7', null, 'holder-a', 'A')->acquired);
        // A's lock lapses and B takes it right after A read it for its release.
        $cache->afterRead = static function () use ($cache, $b): void {
            $cache->expireAll();
            self::assertTrue($b->acquire('form-7', null, 'holder-b', 'B')->acquired);
        };

        $a->release('form-7', null, 'holder-a');

        self::assertSame('holder-b', $b->current('form-7', null)?->holderId, "B's lock survives A's release");
    }

    #[Test]
    public function a_heartbeat_after_the_lock_changed_hands_does_not_take_it_back(): void
    {
        $cache = new InterleavingCache();
        $a = (new CacheBackedFormLockStore())->withCacheManager($cache);
        $b = (new CacheBackedFormLockStore())->withCacheManager($cache);
        self::assertTrue($a->acquire('form-7', null, 'holder-a', 'A')->acquired);
        $cache->afterRead = static function () use ($cache, $b): void {
            $cache->expireAll();
            self::assertTrue($b->acquire('form-7', null, 'holder-b', 'B')->acquired);
        };

        self::assertFalse($a->heartbeat('form-7', null, 'holder-a'));
        self::assertSame('holder-b', $b->current('form-7', null)?->holderId);
    }

    #[Test]
    public function the_same_holder_re_affirms_and_keeps_when_it_acquired(): void
    {
        $cache = new InterleavingCache();
        $a = (new CacheBackedFormLockStore())->withCacheManager($cache);
        $first = $a->acquire('form-7', null, 'holder-a', 'A');
        $again = $a->acquire('form-7', null, 'holder-a', 'A (tab 2)');

        self::assertTrue($again->acquired);
        self::assertSame($first->holder->acquiredAt, $again->holder->acquiredAt);
        self::assertSame('A (tab 2)', $again->holder->holderLabel);
        self::assertTrue($a->heartbeat('form-7', null, 'holder-a'));
    }

    #[Test]
    public function a_replayed_event_is_claimed_once(): void
    {
        $cache = new InterleavingCache();
        $one = (new CacheBackedUiReplayStore())->withCacheManager($cache);
        $two = (new CacheBackedUiReplayStore())->withCacheManager($cache);

        self::assertTrue($one->claim('evt-1', 60));
        self::assertFalse($two->claim('evt-1', 60));
    }
}

/**
 * One store all namespaces share, as Redis is for every worker, with a hook
 * that runs once right after a read: another worker acting mid-operation.
 */
final class InterleavingCache implements CacheManagerInterface
{
    /** @var (\Closure(): void)|null */
    public ?\Closure $afterRead = null;

    /** @var \ArrayObject<string, mixed> */
    private \ArrayObject $data;

    /** @param \ArrayObject<string, mixed>|null $data */
    public function __construct(?\ArrayObject $data = null, private string $ns = '')
    {
        $this->data = $data ?? new \ArrayObject();
    }

    public function expireAll(): void
    {
        $this->data->exchangeArray([]);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->data[$this->ns . '|' . $key] ?? $default;
        if ($this->afterRead !== null) {
            $hook = $this->afterRead;
            $this->afterRead = null;
            $hook();
        }

        return $value;
    }

    public function put(string $key, mixed $value, ?int $ttlSeconds = null, array $tags = []): void
    {
        $this->data[$this->ns . '|' . $key] = $value;
    }

    public function add(string $key, mixed $value, ?int $ttlSeconds = null): bool
    {
        $k = $this->ns . '|' . $key;
        if ($this->data->offsetExists($k)) {
            return false;
        }
        $this->data[$k] = $value;

        return true;
    }

    public function forgetIf(string $key, mixed $expected): bool
    {
        $k = $this->ns . '|' . $key;
        if (!$this->data->offsetExists($k) || $this->data[$k] !== $expected) {
            return false;
        }
        unset($this->data[$k]);

        return true;
    }

    public function remember(string $key, callable $resolver, ?int $ttlSeconds = null, array $tags = []): mixed
    {
        throw new \LogicException('unused');
    }

    public function forget(string $key): void
    {
        unset($this->data[$this->ns . '|' . $key]);
    }

    public function take(string $key): mixed
    {
        throw new \LogicException('unused');
    }

    public function flushTags(string ...$tags): int
    {
        throw new \LogicException('unused');
    }

    public function flushNamespace(?string $namespace = null): int
    {
        throw new \LogicException('unused');
    }

    public function withNamespace(string $namespace): CacheManagerInterface
    {
        // The same data and the same hook holder: a namespace is a key prefix, not another cache.
        $scoped = new self($this->data, $namespace);
        $scoped->afterRead = &$this->afterRead;

        return $scoped;
    }

    public function withTags(string ...$tags): CacheManagerInterface
    {
        return $this;
    }

    public function scope(CacheScope $scope): CacheManagerInterface
    {
        return $this;
    }
}
