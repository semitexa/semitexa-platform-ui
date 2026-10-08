<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;

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
#[AsUiContract(
    summary: 'A single metric: a label, its value, and optionally a change and a caption.',
    props: [
        new UiProp('label', required: true),
        new UiProp('value', required: true, description: 'The value as shown, formatted ("1,284", "€42k").'),
        new UiProp('delta', nullable: true, description: 'The change, as shown ("+12%").'),
        new UiProp('trend', default: 'flat', values: ['up', 'down', 'flat']),
        new UiProp('caption', nullable: true, description: 'One line of context ("vs last week").'),
    ],
    examples: [
        new UiExample('default', 'Revenue', ['label' => 'Revenue', 'value' => '€42,180', 'delta' => '+12%', 'trend' => 'up', 'caption' => 'vs last week']),
    ],
    previewSafe: true,
)]
final class StatComponent
{
}
