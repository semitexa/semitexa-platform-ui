<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Event\InMemoryUiSsePatchQueue;

final class InMemoryUiSsePatchQueueTest extends TestCase
{
    #[Test]
    public function publish_then_drain_returns_in_fifo_order(): void
    {
        $q = new InMemoryUiSsePatchQueue();
        $q->publish('chan', 'a');
        $q->publish('chan', 'b');
        $q->publish('chan', 'c');
        self::assertSame(['a', 'b', 'c'], $q->drain('chan', 10));
    }

    #[Test]
    public function drain_respects_limit_and_leaves_remainder(): void
    {
        $q = new InMemoryUiSsePatchQueue();
        foreach (['a', 'b', 'c', 'd'] as $v) {
            $q->publish('chan', $v);
        }
        self::assertSame(['a', 'b'], $q->drain('chan', 2));
        self::assertSame(['c', 'd'], $q->drain('chan', 10));
    }

    #[Test]
    public function drain_on_empty_channel_returns_empty_list(): void
    {
        $q = new InMemoryUiSsePatchQueue();
        self::assertSame([], $q->drain('nobody', 10));
    }

    #[Test]
    public function distinct_channels_are_isolated(): void
    {
        $q = new InMemoryUiSsePatchQueue();
        $q->publish('a', '1');
        $q->publish('b', '2');
        self::assertSame(['1'], $q->drain('a', 10));
        self::assertSame(['2'], $q->drain('b', 10));
    }

    #[Test]
    public function drain_with_zero_or_negative_limit_returns_empty(): void
    {
        $q = new InMemoryUiSsePatchQueue();
        $q->publish('chan', 'a');
        self::assertSame([], $q->drain('chan', 0));
        self::assertSame([], $q->drain('chan', -5));
        // Original payload still drainable.
        self::assertSame(['a'], $q->drain('chan', 10));
    }

    #[Test]
    public function reset_wipes_all_state(): void
    {
        $q = new InMemoryUiSsePatchQueue();
        $q->publish('a', '1');
        $q->publish('b', '2');
        $q->reset();
        self::assertSame([], $q->drain('a', 10));
        self::assertSame([], $q->drain('b', 10));
    }
}
