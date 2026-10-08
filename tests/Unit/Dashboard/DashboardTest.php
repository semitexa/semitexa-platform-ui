<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Dashboard;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Authorization\Domain\Contract\AuthorizerInterface;
use Semitexa\Authorization\Domain\Enum\DenyReason;
use Semitexa\Authorization\Domain\Model\AccessDecision;
use Semitexa\Authorization\Domain\Model\AccessPolicy;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Auth\AuthenticatableInterface;
use Semitexa\Core\Authorization\SubjectInterface;
use Semitexa\PlatformUi\Application\Service\Access\UiPermissions;
use Semitexa\PlatformUi\Application\Service\Chart\UiChartGeometry;
use Semitexa\PlatformUi\Application\Service\Dashboard\UiDashboards;
use Semitexa\PlatformUi\Application\Service\Dashboard\UiDashboardWidgetInterface;
use Semitexa\PlatformUi\Application\Service\Dashboard\UiWidgetPermissionInterface;
use Semitexa\PlatformUi\Application\Service\Dashboard\UiWidgetWatchesInterface;
use Semitexa\PlatformUi\Application\Component\Builtin\DashboardComponent;
use Semitexa\PlatformUi\Application\Payload\Request\IslandFeedPayload;
use Semitexa\PlatformUi\Application\Service\Island\UiIslandFinisher;
use Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContextBinding;
use Semitexa\PlatformUi\Attribute\AsDashboardWidget;
use Semitexa\PlatformUi\Domain\Model\Dashboard\UiWidget;

/**
 * tk-rs-dashboard: a dashboard is its widgets in order, minus the ones the
 * visitor may not see (never even computed), with a failing one shown as
 * unavailable instead of breaking the page; and a chart is plain geometry.
 */
final class DashboardTest extends TestCase
{
    protected function setUp(): void
    {
        UiDashboards::discover([SecretWidgetFixture::class, PublicWidgetFixture::class, BrokenWidgetFixture::class, ScreenWidgetFixture::class], static fn (string $c): object => new $c());
        PublicWidgetFixture::$computed = SecretWidgetFixture::$computed = 0;
    }

    protected function tearDown(): void
    {
        UiDashboards::reset();
        UiPermissions::reset();
    }

    #[Test]
    public function the_visitor_sees_the_widgets_they_may_in_order_and_a_failure_as_unavailable(): void
    {
        $this->grant(['reports.view', 'shop.read']);
        $rendered = UiDashboards::render('admin');

        self::assertSame(['public-widget-fixture', 'secret-widget-fixture', 'broken-widget-fixture', 'screen-widget-fixture'], array_column($rendered, 'id'));
        self::assertSame('Visits', $rendered[0]['widget']?->title);
        self::assertNull($rendered[2]['widget'], 'a widget that throws is unavailable, not a broken page');
        self::assertTrue($rendered[1]['wide']);
    }

    #[Test]
    public function a_widget_without_its_permission_is_never_computed(): void
    {
        $this->grant([]);
        $rendered = UiDashboards::render('admin');

        self::assertSame(['public-widget-fixture', 'broken-widget-fixture'], array_column($rendered, 'id'), 'attribute permission and stated permission both hide');
        self::assertSame(0, SecretWidgetFixture::$computed);
        self::assertSame([], UiDashboards::render('nowhere'));
    }

    #[Test]
    public function the_island_watches_what_the_visitor_s_widgets_read_and_no_more(): void
    {
        $this->grant(['reports.view', 'shop.read']);
        self::assertSame(['orders', 'refunds', 'products'], UiDashboards::watches('admin'));

        $this->grant([]);
        self::assertSame([], UiDashboards::watches('admin'), 'a widget the visitor may not see redraws nothing for them');
        self::assertSame(0, SecretWidgetFixture::$computed, 'reading what a widget watches does not compute it');

        $this->grant(['shop.read']);
        $island = new DashboardComponent();
        self::assertSame(['orders', 'products'], $island->watches(['name' => 'admin']), 'the dashboard island watches what its widgets read');
        self::assertSame([], $island->watches(['name' => 'nowhere']));
        self::assertSame([], $island->watches([]));
    }

    /**
     * tk-ls-islands: an island drawn for a signed-in visitor carries a signed
     * token naming itself — the only thing the island feed takes — and nothing
     * else is marked.
     */
    #[Test]
    public function an_island_drawn_for_a_signed_in_visitor_carries_its_signed_token(): void
    {
        SignedContextBinding::bind('session-1', 'tenant-1');
        try {
            $this->grant(['shop.read']);
            $finisher = new UiIslandFinisher();
            $html = $finisher->finish('platform.dashboard', DashboardComponent::class, 'uci_island_1', ['name' => 'admin'], '<section data-ui-dashboard="admin"><p>x</p></section>');

            self::assertSame(1, preg_match('/<section data-ui-dashboard="admin" data-ui-component="platform.dashboard" data-ui-component-instance-id="uci_island_1" data-ui-island="(sc1\.[^"]+)">/', $html, $m));
            // The page gets its KISS session in <head>, once — not in front of
            // every island, which left one copy per island on the page.
            self::assertStringStartsWith('<section', $html, 'no meta printed in front of the island');
            self::assertStringContainsString('<meta name="semitexa-ui-sse-session"', AssetCollectorStore::get()->takeHeadTags(''));

            $feed = new IslandFeedPayload();
            $feed->setIsland($m[1]);
            self::assertSame(['component' => 'platform.dashboard', 'instance' => 'uci_island_1', 'props' => ['name' => 'admin']], $feed->island());
            $feed->setDrawn('1');
            self::assertTrue($feed->takeDrawn(), 'the first subscribe: the page shows this render already');
            self::assertFalse($feed->takeDrawn(), 'taken once: the re-runs draw');
            $feed->setIsland(substr($m[1], 0, -2) . 'xx');
            self::assertNull($feed->island(), 'a token that does not verify names no island');
            self::assertSame([], $feed->dynamicWatchScopes());

            $plain = '<div>not an island</div>';
            self::assertSame($plain, $finisher->finish('x.plain', \stdClass::class, 'uci_2', [], $plain));
            UiPermissions::reset();
            self::assertSame($plain, $finisher->finish('platform.dashboard', DashboardComponent::class, 'uci_3', ['name' => 'admin'], $plain), 'a guest gets the island as drawn');
        } finally {
            SignedContextBinding::clear();
        }
    }

    #[Test]
    public function a_chart_is_geometry_scaled_from_zero(): void
    {
        $bars = UiChartGeometry::bars([2, 4, 0], 120, 40);
        self::assertSame([20.0, 40.0, 1.0], array_column($bars, 'height'), 'equal steps, and a zero is still a hairline');

        $line = UiChartGeometry::line([0, 5, 10], 100, 20, 0);
        self::assertSame([['x' => 0.0, 'y' => 20.0], ['x' => 50.0, 'y' => 10.0], ['x' => 100.0, 'y' => 0.0]], $line['points']);
        self::assertSame('M0 20 L50 10 L100 0', $line['path']);
        self::assertStringEndsWith('Z', $line['area']);
        self::assertSame(['path' => '', 'area' => '', 'points' => []], UiChartGeometry::line([], 100, 20));
    }

    #[Test]
    public function a_widget_shape_is_checked_where_it_is_made(): void
    {
        $stat = UiWidget::stat('Orders', '12', series: ['Oct 5' => 1, 'Oct 6' => 3])->withLink('/orders', 'All orders');
        self::assertSame([['label' => 'Oct 5', 'value' => 1], ['label' => 'Oct 6', 'value' => 3]], $stat->props['points']);
        self::assertSame(['/orders', 'All orders'], [$stat->href, $stat->linkText]);

        $this->expectException(\InvalidArgumentException::class);
        UiWidget::chart('Orders', 'pie', []);
    }

    /** @param list<string> $permissions */
    private function grant(array $permissions): void
    {
        $user = new class implements AuthenticatableInterface {
            public function getId(): string { return 'u'; }
            public function getAuthIdentifierName(): string { return 'id'; }
            public function getAuthIdentifier(): mixed { return 'u'; }
        };
        $auth = new class($user) implements AuthContextInterface {
            public function __construct(private ?AuthenticatableInterface $user) {}
            public function getUser(): ?AuthenticatableInterface { return $this->user; }
            public function isGuest(): bool { return false; }
            public function setUser(?AuthenticatableInterface $user): void { $this->user = $user; }
            public static function get(): ?AuthContextInterface { return null; }
            public static function getOrFail(): AuthContextInterface { throw new \LogicException('not used'); }
        };
        UiPermissions::use($auth, new class($permissions) implements AuthorizerInterface {
            /** @param list<string> $granted */
            public function __construct(private array $granted) {}
            public function authorize(SubjectInterface $subject, AccessPolicy $policy): AccessDecision
            {
                return array_diff($policy->requiredPermissions, $this->granted) === [] ? AccessDecision::allow() : AccessDecision::denyForbidden(DenyReason::PermissionRequired);
            }
        });
    }
}

#[AsDashboardWidget(dashboard: 'admin', order: 10)]
final class PublicWidgetFixture implements UiDashboardWidgetInterface
{
    public static int $computed = 0;
    public function widget(): UiWidget { self::$computed++; return UiWidget::stat('Visits', '3'); }
}

#[AsDashboardWidget(dashboard: 'admin', order: 20, permission: 'reports.view', wide: true)]
final class SecretWidgetFixture implements UiDashboardWidgetInterface, UiWidgetWatchesInterface
{
    public static function watches(): array { return ['orders', ' ', 'refunds']; }
    public static int $computed = 0;
    public function widget(): UiWidget { self::$computed++; return UiWidget::chart('Revenue', 'line', [['label' => 'a', 'value' => 1]]); }
}

#[AsDashboardWidget(dashboard: 'admin', order: 30)]
final class BrokenWidgetFixture implements UiDashboardWidgetInterface
{
    public function widget(): UiWidget { throw new \RuntimeException('database is down'); }
}

#[AsDashboardWidget(dashboard: 'admin', order: 40)]
final class ScreenWidgetFixture implements UiDashboardWidgetInterface, UiWidgetPermissionInterface, UiWidgetWatchesInterface
{
    public static function watches(): array { return ['orders', 'products']; }
    public static function requiredPermission(): ?string { return 'shop.read'; }
    public function widget(): UiWidget { return UiWidget::list('Recent', []); }
}
