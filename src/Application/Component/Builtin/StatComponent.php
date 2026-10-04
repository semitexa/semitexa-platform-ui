<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin;

use Semitexa\Ssr\Attribute\AsComponent;
use Semitexa\PlatformUi\Attribute\UiSlot;
use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;

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
#[AsUiContract(
    summary: 'A single key metric with its change over time.',
    props: [
        new UiProp('label', default: ''),
        new UiProp('value', default: ''),
        new UiProp('delta', nullable: true),
        new UiProp('trend', default: 'flat', values: ['up', 'down', 'flat']),
        new UiProp('caption', nullable: true),
    ],
    examples: [
        new UiExample('up', 'Growing', ['label' => 'Revenue', 'value' => '$48,210', 'delta' => '+12.4%', 'trend' => 'up', 'caption' => 'vs last month']),
        new UiExample('down', 'Falling', ['label' => 'Churn', 'value' => '2.1%', 'delta' => '-0.4%', 'trend' => 'down', 'caption' => 'vs last month']),
        new UiExample('flat', 'Flat', ['label' => 'Active users', 'value' => '1,204', 'delta' => '0%']),
    ],
    previewSafe: true,
)]
final class StatComponent
{
}
