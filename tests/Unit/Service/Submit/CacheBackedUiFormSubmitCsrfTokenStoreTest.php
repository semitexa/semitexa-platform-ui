<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Service\Submit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cache\Domain\Contract\CacheManagerInterface;
use Semitexa\Cache\Domain\Enum\CacheScope;
use Semitexa\PlatformUi\Application\Service\Submit\CacheBackedUiFormSubmitCsrfTokenStore;

/**
 * A form's one-time token is spent once, however many workers hold it.
 *
 * REGRESSION (review ultimate#130, tk-csrf-consume-atomic). consume() read the
 * token, compared it and forgot it in separate calls: two workers holding the
 * same valid token both read it before either forgot it, and both ran the
 * action. Here the second worker consumes while the first is between its read
 * and its removal, on one cache both share, as on one Redis.
 */
final class CacheBackedUiFormSubmitCsrfTokenStoreTest extends TestCase
{
    #[Test]
    public function a_token_issued_by_one_worker_is_consumed_by_another_once(): void
    {
        $shared = self::sharedCache();
        $handle = self::worker($shared)->issue(60);

        self::assertTrue(self::worker($shared)->consume($handle->id, $handle->raw));
        self::assertFalse(self::worker($shared)->consume($handle->id, $handle->raw), 'spent');
    }

    #[Test]
    public function of_two_workers_consuming_one_token_at_once_only_one_runs_the_action(): void
    {
        $shared = self::sharedCache();
        $handle = self::worker($shared)->issue(60);
        $second = self::worker($shared);

        $secondConsumed = null;
        $shared->between = static function () use ($second, $handle, &$secondConsumed): void {
            $secondConsumed = $second->consume($handle->id, $handle->raw);
        };
        $firstConsumed = self::worker($shared)->consume($handle->id, $handle->raw);

        // verify:accept-test-change the sum alone passed when the hook never ran: each worker's outcome is named now
        self::assertNotNull($secondConsumed, 'the second worker ran between the first one\'s read and its removal');
        self::assertTrue($secondConsumed, 'it removed the token first, so it consumed it');
        self::assertFalse($firstConsumed, 'the first read it too, but removed nothing');
    }

    #[Test]
    public function a_wrong_token_spends_nothing(): void
    {
        $shared = self::sharedCache();
        $handle = self::worker($shared)->issue(60);

        self::assertFalse(self::worker($shared)->consume($handle->id, 'guess'));
        self::assertTrue(self::worker($shared)->consume($handle->id, $handle->raw), 'the real one still works');
    }

    private static function worker(CacheManagerInterface $shared): CacheBackedUiFormSubmitCsrfTokenStore
    {
        return (new CacheBackedUiFormSubmitCsrfTokenStore())->withCacheManager($shared);
    }

    /** One store every worker reads; `between` runs once, right after a read: the other worker. */
    private static function sharedCache(): CacheManagerInterface
    {
        return new class (new \ArrayObject()) implements CacheManagerInterface {
            /** @var (\Closure(): void)|null */
            public ?\Closure $between = null;

            /** @param \ArrayObject<string, mixed> $data */
            public function __construct(private readonly \ArrayObject $data, private readonly string $ns = '') {}

            public function get(string $key, mixed $default = null): mixed
            {
                $value = $this->data[$this->ns . '|' . $key] ?? $default;
                if ($this->between !== null) {
                    $other = $this->between;
                    $this->between = null;
                    $other();
                }

                return $value;
            }

            public function put(string $key, mixed $value, ?int $ttlSeconds = null, array $tags = []): void
            {
                $this->data[$this->ns . '|' . $key] = $value;
            }

            public function forget(string $key): void
            {
                unset($this->data[$this->ns . '|' . $key]);
            }

            public function take(string $key): mixed
            {
                $k = $this->ns . '|' . $key;
                if (!$this->data->offsetExists($k)) {
                    return null;
                }
                $value = $this->data[$k];
                unset($this->data[$k]);

                return $value;
            }

            public function withNamespace(string $namespace): CacheManagerInterface
            {
                // The same store and the same hook: a namespace is a key prefix, not another cache.
                $scoped = new self($this->data, $namespace);
                $scoped->between = &$this->between;

                return $scoped;
            }

            public function remember(string $key, callable $resolver, ?int $ttlSeconds = null, array $tags = []): mixed { throw new \LogicException('not used'); }
            public function flushTags(string ...$tags): int { throw new \LogicException('not used'); }
            public function flushNamespace(?string $namespace = null): int { throw new \LogicException('not used'); }
            public function withTags(string ...$tags): CacheManagerInterface { throw new \LogicException('not used'); }
            public function scope(CacheScope $scope): CacheManagerInterface { throw new \LogicException('not used'); }
        };
    }
}
