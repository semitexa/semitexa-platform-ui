<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin;

use Semitexa\Ssr\Attribute\AsComponent;
use Semitexa\PlatformUi\Attribute\UiSlot;

/**
 * platform.stat — a single KPI / metric display.
 *
 * Shows a label, a prominent value, and an optional delta whose colour is
 * driven by `trend` (up = success, down = danger, flat = muted). The
 * `visual` slot holds an optional leading icon or sparkline.
 *
 * Props:
 *   - label   — the metric name.
 *   - value   — the metric value (string, pre-formatted).
 *   - delta   — optional change indicator text (e.g. "+12%").
 *   - trend   — up | down | flat (colours the delta; default flat).
 *   - caption — optional secondary line under the value.
 * Slot:
 *   - visual  — optional leading icon or mini-chart.
 *
 * Styling: css/components.css, `[ui-component="stat"]`.
 */
#[AsComponent(
    name: 'platform.stat',
    template: '@platform-ui/components/runtime/stat.html.twig',
    cacheable: true,
)]
#[UiSlot(name: 'visual', description: 'Optional leading icon or mini-chart shown beside the metric.')]
final class StatComponent
{
}
