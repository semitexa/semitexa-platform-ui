<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Island;

/**
 * A component that is an island: it says what it reads, and a write to that
 * redraws it on every open page — re-rendered on the server with the props it
 * was drawn with, morphed in place on the page (ep-platform-live-state).
 *
 *     #[AsComponent(name: 'shop.open-orders', template: '…')]
 *     final class OpenOrdersComponent implements UiIslandInterface
 *     {
 *         public function watches(array $props): array
 *         {
 *             return [ResourceMetadata::for(OrderResource::class)->getResourceKey()];
 *         }
 *     }
 *
 * The keys are invalidation scopes: an ORM model's resource key, which every
 * ORM write to it publishes, or a key a raw write touches through
 * ScopeInvalidatorInterface. Asked per visitor, so it may depend on what they
 * may see. Live for a signed-in visitor (the island feed is protected); a guest
 * sees the island as it was drawn.
 */
interface UiIslandInterface
{
    /**
     * @param array<array-key, mixed> $props the props this instance was drawn with
     * @return list<string>
     */
    public function watches(array $props): array;
}
