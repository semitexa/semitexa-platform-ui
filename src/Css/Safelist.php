<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Css;

use Semitexa\PlatformUi\Application\Service\Asset\SliceRegistry;

/**
 * Allow-list of slices that extraction cannot see (dynamic Twig values, JS-set attrs).
 * Scopes: global (all routes), per-route. Package-level defaults are registered by consumers.
 */
final class Safelist
{
    /** @var array<string, true> */
    private array $globalSlices = [];

    /** @var array<string, true> */
    private array $globalPrimitives = [];

    /** @var array<string, array<string, true>> route => slice-id => true */
    private array $routeSlices = [];

    /** @var array<string, array<string, true>> */
    private array $routePrimitives = [];

    public function addGlobalSlice(string $sliceId): void
    {
        $this->globalSlices[$sliceId] = true;
    }

    public function addGlobalPrimitive(string $primitiveId): void
    {
        $this->globalPrimitives[$primitiveId] = true;
    }

    public function addRouteSlice(string $route, string $sliceId): void
    {
        $this->routeSlices[$route][$sliceId] = true;
    }

    public function addRoutePrimitive(string $route, string $primitiveId): void
    {
        $this->routePrimitives[$route][$primitiveId] = true;
    }

    public function apply(SliceRegistry $registry, ?string $route = null): void
    {
        foreach (array_keys($this->globalSlices) as $sliceId) {
            $registry->registerSlice($sliceId);
        }
        foreach (array_keys($this->globalPrimitives) as $id) {
            $registry->registerPrimitive($id);
        }
        if ($route !== null) {
            foreach (array_keys($this->routeSlices[$route] ?? []) as $sliceId) {
                $registry->registerSlice($sliceId);
            }
            foreach (array_keys($this->routePrimitives[$route] ?? []) as $id) {
                $registry->registerPrimitive($id);
            }
        }
    }
}
