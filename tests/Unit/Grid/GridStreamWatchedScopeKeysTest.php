<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Grid;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Handler\PayloadHandler\AbstractGridStreamFeedHandler;
use Semitexa\PlatformUi\Attribute\GridFeed;

/**
 * Live-on-events Phase 2 (subscribe side): pin that the held-open grid stream's
 * watched scopes — {@see \Semitexa\Ssr\Domain\Model\SubscriptionRecord::$scopeKeys}
 * — are SOURCED from the grid's `#[GridFeed(liveOn:)]` DECLARATION, matched by
 * the feed route, and NOT from any hardcoded handler constant.
 *
 * The resolution is the pure static seam
 * {@see AbstractGridStreamFeedHandler::watchedScopeKeysForRoute()} the feed
 * handler's `buildSubscriptionRecord()` calls. These tests exercise it directly
 * over fixture component classes (no Swoole / SSR / container needed), which is
 * exactly the value the feed handler threads into `SubscriptionRecord`.
 */
final class GridStreamWatchedScopeKeysTest extends TestCase
{
    /** @var list<class-string> */
    private const FEED_CLASSES = [
        LeadsLikeFeedFixture::class,
        MultiScopeFeedFixture::class,
        StaticFeedNoLiveOnFixture::class,
        NotAFeedFixture::class,
    ];

    #[Test]
    public function the_declared_live_on_single_scope_becomes_the_subscription_for_its_route(): void
    {
        // Leads' shape: the feed route resolves to the declared scope — byte-equal
        // to the channel the retired hardcoded gridStreamWatchedScopeKey() produced.
        self::assertSame(
            ['ui_playground_leads'],
            AbstractGridStreamFeedHandler::watchedScopeKeysForRoute(
                self::FEED_CLASSES,
                '/leads/grid-stream',
            ),
        );
    }

    #[Test]
    public function a_multi_scope_live_on_subscribes_to_all_declared_scopes_in_order(): void
    {
        // liveOn: [a, b] → a 2-entry scopeKeys subscribed to BOTH (OR semantics):
        // ANY firing re-runs. The list threads through verbatim, declaration order
        // preserved.
        self::assertSame(
            ['scope_a', 'scope_b'],
            AbstractGridStreamFeedHandler::watchedScopeKeysForRoute(
                self::FEED_CLASSES,
                '/multi/grid-stream',
            ),
        );
    }

    #[Test]
    public function a_feed_with_no_live_on_yields_an_empty_subscription(): void
    {
        // No declared scope → empty scopeKeys → no live subscription (static grid).
        self::assertSame(
            [],
            AbstractGridStreamFeedHandler::watchedScopeKeysForRoute(
                self::FEED_CLASSES,
                '/static/grid-stream',
            ),
        );
    }

    #[Test]
    public function an_unmatched_route_yields_an_empty_subscription(): void
    {
        // No #[GridFeed] declares this route → empty (the default-OFF path; the
        // record still registers for view-change, it just watches no channel).
        self::assertSame(
            [],
            AbstractGridStreamFeedHandler::watchedScopeKeysForRoute(
                self::FEED_CLASSES,
                '/unknown/grid-stream',
            ),
        );
    }

    #[Test]
    public function resolution_is_keyed_on_the_declared_feed_route_not_class_order(): void
    {
        // Each route resolves to ITS OWN declaration regardless of scan order —
        // the route is the contract linking handler ⇄ component.
        self::assertSame(
            ['ui_playground_leads'],
            AbstractGridStreamFeedHandler::watchedScopeKeysForRoute(
                array_reverse(self::FEED_CLASSES),
                '/leads/grid-stream',
            ),
        );
    }
}

/** Leads' shape: a single declared live-on scope on its feed route. */
#[GridFeed(route: '/leads/grid-stream', liveOn: ['ui_playground_leads'])]
final class LeadsLikeFeedFixture
{
}

/** Two declared scopes → both subscribed (OR semantics, structure proof). */
#[GridFeed(route: '/multi/grid-stream', liveOn: ['scope_a', 'scope_b'])]
final class MultiScopeFeedFixture
{
}

/** A feed with no liveOn → empty subscription (static). */
#[GridFeed(route: '/static/grid-stream')]
final class StaticFeedNoLiveOnFixture
{
}

/** No #[GridFeed] at all → skipped by the resolver. */
final class NotAFeedFixture
{
}
