<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Twig;

use Semitexa\PlatformUi\Application\Service\Chart\UiChartGeometry;
use Semitexa\Ssr\Application\Service\Extension\TwigExtensionRegistry;
use Semitexa\Ssr\Attribute\AsTwigExtension;

#[AsTwigExtension]
final class ChartTwigExtension
{
    public function registerFunctions(): void
    {
        /**
         * ui_chart(values, kind, width, height)
         *
         * The shapes platform.chart draws: for 'line' {path, area, points},
         * for 'bars' a list of {x, y, width, height}. Non-numbers count as 0.
         */
        TwigExtensionRegistry::registerFunction(
            'ui_chart',
            static function (array $values, string $kind = 'line', int $width = 120, int $height = 32): array {
                $numbers = array_values(array_map(static fn (mixed $v): float => is_numeric($v) ? (float) $v : 0.0, $values));

                return $kind === 'bars'
                    ? UiChartGeometry::bars($numbers, $width, $height)
                    : UiChartGeometry::line($numbers, $width, $height);
            },
        );
    }
}
