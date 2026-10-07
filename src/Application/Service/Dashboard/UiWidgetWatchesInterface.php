<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Dashboard;

/**
 * A dashboard widget that says what it reads, so a write to it redraws the
 * widget on every open dashboard. The keys are invalidation scopes: an ORM
 * model's resource key (its table name, unless #[ResourceKey] says otherwise),
 * which every ORM write to the model publishes, or any key a raw write
 * touches through ScopeInvalidatorInterface.
 *
 *     public static function watches(): array
 *     {
 *         return [ResourceMetadata::for(OrderResource::class)->getResourceKey()];
 *     }
 *
 * Static, like requiredPermission(): it is read without computing the widget.
 */
interface UiWidgetWatchesInterface
{
    /** @return list<string> */
    public static function watches(): array;
}
