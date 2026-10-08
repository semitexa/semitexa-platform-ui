<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;
use Semitexa\Ssr\Attribute\AsComponent;

/**
 * platform.chart — a small line or bar chart drawn as SVG on the server: no
 * chart library, no script. It is an image with a name, and its numbers are
 * also a table for a screen reader.
 *
 *     {{ component('platform.chart', {title: 'Orders, last 14 days', kind: 'bars',
 *         points: [{label: 'Mon', value: 3}, …]}) }}
 */
#[AsComponent(
    name: 'platform.chart',
    template: '@platform-ui/components/runtime/chart.html.twig',
    cacheable: true,
)]
#[AsUiContract(
    summary: 'A small server-rendered SVG chart (sparkline line or bars) with an accessible name and a data table.',
    props: [
        new UiProp('title', required: true, description: 'What the chart shows; its accessible name.'),
        new UiProp('points', type: UiPropType::Array, required: true, description: '[{label, value}] in order.'),
        new UiProp('kind', default: 'line', description: 'line | bars'),
        new UiProp('height', type: UiPropType::Integer, default: 48, description: 'Drawing height in px; the chart fills its width.'),
        new UiProp('showTitle', type: UiPropType::Boolean, default: false, description: 'Print the title above the chart (it is always the accessible name).'),
    ],
    examples: [
        new UiExample('bars', 'Bars', ['title' => 'Orders this week', 'kind' => 'bars', 'points' => [['label' => 'Mon', 'value' => 3], ['label' => 'Tue', 'value' => 5], ['label' => 'Wed', 'value' => 2], ['label' => 'Thu', 'value' => 6], ['label' => 'Fri', 'value' => 4]]]),
        new UiExample('line', 'Line', ['title' => 'Sign-ups', 'points' => [['label' => '1', 'value' => 1], ['label' => '2', 'value' => 4], ['label' => '3', 'value' => 3], ['label' => '4', 'value' => 7]]]),
    ],
)]
final class ChartComponent
{
}
