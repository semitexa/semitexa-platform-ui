<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Component;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Component\Builtin\GridComponent;
use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\Ssr\Attribute\AsComponent;

/**
 * tk-cm-grid-component: the grid is a real component — a class the catalog
 * and the Workbench know — not a Twig include nobody can find.
 */
final class GridComponentTest extends TestCase
{
    #[Test]
    public function the_grid_is_a_component_with_a_contract(): void
    {
        $ref = new \ReflectionClass(GridComponent::class);
        $component = $ref->getAttributes(AsComponent::class)[0]->newInstance();
        self::assertSame('platform.grid', $component->name);
        self::assertFileExists(\dirname(__DIR__, 3) . '/resources/twig/components/runtime/grid-v2.html.twig');

        $contract = $ref->getAttributes(AsUiContract::class)[0]->newInstance();
        $props = array_map(static fn ($p): string => $p->name, $contract->props);
        self::assertSame(['endpoint', 'gridId', 'emptyMessage', 'actions', 'rowActions', 'urlState', 'serverActions', 'actionHandler'], $props);
        self::assertFalse($contract->previewSafe, 'a grid needs a live feed of the host application');
    }

    #[Test]
    public function the_calendar_is_a_component_with_a_contract(): void
    {
        $ref = new \ReflectionClass(\Semitexa\PlatformUi\Application\Component\Builtin\CalendarComponent::class);
        self::assertSame('platform.calendar', $ref->getAttributes(AsComponent::class)[0]->newInstance()->name);
        $contract = $ref->getAttributes(AsUiContract::class)[0]->newInstance();
        self::assertSame(['endpoint', 'feed', 'view', 'userId'], array_map(static fn ($p): string => $p->name, $contract->props));
    }
}
