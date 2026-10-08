<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Chart;

/**
 * The shapes of a small chart, computed on the server so the page needs no
 * chart library and no script: a line (with its filled area) or bars, in a
 * viewBox of the given size. Values are scaled from zero (or the lowest
 * negative value) to the highest, so equal values draw equal heights.
 */
final class UiChartGeometry
{
    /**
     * @param list<int|float> $values
     * @return array{path: string, area: string, points: list<array{x: float, y: float}>}
     */
    public static function line(array $values, int $width, int $height, int $pad = 2): array
    {
        if ($values === []) {
            return ['path' => '', 'area' => '', 'points' => []];
        }
        [$low, $span] = self::range($values);
        $count = count($values);
        $step = $count > 1 ? ($width - 2 * $pad) / ($count - 1) : 0.0;
        $points = [];
        foreach ($values as $i => $value) {
            $points[] = [
                'x' => round($count > 1 ? $pad + $i * $step : $width / 2, 2),
                'y' => round($height - $pad - (($value - $low) / $span) * ($height - 2 * $pad), 2),
            ];
        }
        $path = 'M' . implode(' L', array_map(static fn (array $p): string => $p['x'] . ' ' . $p['y'], $points));
        $last = $points[$count - 1];

        return [
            'path' => $path,
            'area' => $path . sprintf(' L%s %s L%s %s Z', $last['x'], $height, $points[0]['x'], $height),
            'points' => $points,
        ];
    }

    /**
     * @param list<int|float> $values
     * @return list<array{x: float, y: float, width: float, height: float}>
     */
    public static function bars(array $values, int $width, int $height, float $gapRatio = 0.25): array
    {
        if ($values === []) {
            return [];
        }
        [$low, $span] = self::range($values);
        $slot = $width / count($values);
        $barWidth = $slot * (1 - $gapRatio);
        $bars = [];
        foreach ($values as $i => $value) {
            // A zero still shows as a hairline, so "none" reads differently from "missing".
            $barHeight = max(1.0, (($value - $low) / $span) * $height);
            $bars[] = [
                'x' => round($i * $slot + ($slot - $barWidth) / 2, 2),
                'y' => round($height - $barHeight, 2),
                'width' => round($barWidth, 2),
                'height' => round($barHeight, 2),
            ];
        }

        return $bars;
    }

    /**
     * @param list<int|float> $values
     * @return array{0: float, 1: float} the baseline and the span above it (never 0)
     */
    private static function range(array $values): array
    {
        $low = min(0, min($values));
        $span = max($values) - $low;

        return [(float) $low, $span > 0 ? (float) $span : 1.0];
    }
}
