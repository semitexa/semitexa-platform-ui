<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Dashboard;

use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\PlatformUi\Application\Service\Access\UiPermissions;
use Semitexa\PlatformUi\Attribute\AsDashboardWidget;
use Semitexa\PlatformUi\Domain\Model\Dashboard\UiWidget;

/**
 * The #[AsDashboardWidget] classes by dashboard, discovered at worker boot,
 * each resolved per page view from the request scope. A widget the visitor
 * lacks the permission for (its attribute's, or the one it states itself —
 * UiWidgetPermissionInterface) is never resolved; a widget that fails is shown as
 * unavailable (and logged) instead of taking the whole page down.
 */
final class UiDashboards
{
    /** @var array<string, list<array{class: class-string, attribute: AsDashboardWidget}>> */
    private static array $widgets = [];

    /** @var (\Closure(class-string): object)|null */
    private static ?\Closure $resolve = null;

    /**
     * @param iterable<class-string>         $classes
     * @param callable(class-string): object $resolve
     */
    public static function discover(iterable $classes, callable $resolve): void
    {
        $widgets = [];
        foreach ($classes as $class) {
            $attributes = (new \ReflectionClass($class))->getAttributes(AsDashboardWidget::class);
            if ($attributes === []) {
                continue;
            }
            if (!is_subclass_of($class, UiDashboardWidgetInterface::class)) {
                throw new \LogicException(sprintf('#[AsDashboardWidget] class %s must implement %s.', $class, UiDashboardWidgetInterface::class));
            }
            $attribute = $attributes[0]->newInstance();
            $widgets[$attribute->dashboard][] = ['class' => $class, 'attribute' => $attribute];
        }
        foreach ($widgets as &$list) {
            usort($list, static fn (array $a, array $b): int => [$a['attribute']->order, $a['class']] <=> [$b['attribute']->order, $b['class']]);
        }
        unset($list);
        self::$widgets = $widgets;
        self::$resolve = \Closure::fromCallable($resolve);
    }

    public static function reset(): void
    {
        self::$widgets = [];
        self::$resolve = null;
    }

    /**
     * The widgets of a dashboard the visitor may see, in order, each with what
     * it shows — or `widget: null` when it failed.
     *
     * @return list<array{id: string, wide: bool, widget: ?UiWidget}>
     */
    public static function render(string $dashboard): array
    {
        $out = [];
        foreach (self::visible($dashboard) as $entry) {
            $out[] = ['id' => self::idOf($entry['class']), 'wide' => $entry['attribute']->wide, 'widget' => self::compute($dashboard, $entry['class'])];
        }

        return $out;
    }

    /**
     * The widgets of a dashboard the visitor may see, in order, NOT computed —
     * what the dashboard lays out before each widget is drawn (lazily, all at once).
     *
     * @return list<array{id: string, wide: bool}>
     */
    public static function entries(string $dashboard): array
    {
        return array_map(
            static fn (array $entry): array => ['id' => self::idOf($entry['class']), 'wide' => $entry['attribute']->wide],
            self::visible($dashboard),
        );
    }

    /**
     * One widget of a dashboard, computed now — null when the visitor may not
     * see it, there is no such widget, or it failed (logged).
     */
    public static function widget(string $dashboard, string $id): ?UiWidget
    {
        foreach (self::visible($dashboard) as $entry) {
            if (self::idOf($entry['class']) === $id) {
                return self::compute($dashboard, $entry['class']);
            }
        }

        return null;
    }

    /** @param class-string $class */
    private static function compute(string $dashboard, string $class): ?UiWidget
    {
        try {
            $handler = self::$resolve !== null ? (self::$resolve)($class) : new $class();

            return $handler instanceof UiDashboardWidgetInterface ? $handler->widget() : null;
        } catch (\Throwable $e) {
            StaticLoggerBridge::error('platform-ui', 'Dashboard widget failed', [
                'dashboard' => $dashboard,
                'widget' => $class,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private static function idOf(string $class): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', substr($class, (int) strrpos($class, '\\') + 1)));
    }

    /**
     * What the widgets of a dashboard the visitor may see read
     * (UiWidgetWatchesInterface): the scopes whose writes redraw it.
     *
     * @return list<string>
     */
    public static function watches(string $dashboard): array
    {
        $scopes = [];
        foreach (self::visible($dashboard) as $entry) {
            if (is_subclass_of($entry['class'], UiWidgetWatchesInterface::class)) {
                foreach ($entry['class']::watches() as $scope) {
                    $scopes[] = trim($scope);
                }
            }
        }

        return array_values(array_unique(array_filter($scopes, static fn (string $s): bool => $s !== '')));
    }

    /** @return list<array{class: class-string, attribute: AsDashboardWidget}> */
    private static function visible(string $dashboard): array
    {
        $visible = [];
        foreach (self::$widgets[$dashboard] ?? [] as $entry) {
            $permission = $entry['attribute']->permission;
            if ($permission !== null && !UiPermissions::allows($permission)) {
                continue;
            }
            if (is_subclass_of($entry['class'], UiWidgetPermissionInterface::class)
                && !UiPermissions::permits($entry['class']::requiredPermission())) {
                continue;
            }
            $visible[] = $entry;
        }

        return $visible;
    }
}
