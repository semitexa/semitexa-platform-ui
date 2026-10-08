<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Url;

use Semitexa\Ssr\Domain\Contract\ComponentPropsOverlayInterface;

/** Restores `#[UiUrl]` props from the page request's query string. */
final class UiUrlPropsOverlay implements ComponentPropsOverlayInterface
{
    public function overlay(string $componentName, array $props, array $query): array
    {
        return UiUrlBindings::restore($componentName, $props, $query);
    }
}
