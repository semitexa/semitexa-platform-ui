<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin\Block;

use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;
use Semitexa\Ssr\Attribute\AsComponent;

/**
 * platform.block-stats — a row of headline numbers for a marketing page (a
 * dashboard KPI is platform.stat). A description list, so each number keeps
 * its label for a screen reader. Styling: css/blocks.css.
 */
#[AsComponent(
    name: 'platform.block-stats',
    template: '@platform-ui/components/runtime/blocks/stats.html.twig',
    cacheable: true,
)]
#[AsUiContract(
    summary: 'A row of headline numbers, each with its label — for a page, not a dashboard (that is platform.stat).',
    props: [
        new UiProp('title', default: ''),
        new UiProp('lead', default: ''),
        new UiProp('items', UiPropType::Array, required: true, items: new UiProp('stat', UiPropType::Object, properties: [
            new UiProp('value', required: true, description: 'Pre-formatted: "99.9%", "12k".'),
            new UiProp('label', required: true),
            new UiProp('caption', default: ''),
        ])),
    ],
    examples: [
        new UiExample('default', 'Four numbers', [
            'items' => [
                ['value' => '1,866', 'label' => 'icons'],
                ['value' => '2', 'label' => 'transport doors'],
                ['value' => '0', 'label' => 'client frameworks'],
                ['value' => 'AA', 'label' => 'contrast target'],
            ],
        ]),
    ],
    previewSafe: true,
)]
final class StatsBlockComponent
{
}
