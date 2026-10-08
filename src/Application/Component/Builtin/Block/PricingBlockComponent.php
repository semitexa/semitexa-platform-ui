<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin\Block;

use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;
use Semitexa\Ssr\Attribute\AsComponent;

/**
 * platform.block-pricing — plans side by side: a name, a price, what is
 * included and one action each; one plan may be featured. The prices are
 * text the project formats — the block does no currency maths.
 * Styling: css/blocks.css.
 */
#[AsComponent(
    name: 'platform.block-pricing',
    template: '@platform-ui/components/runtime/blocks/pricing.html.twig',
    cacheable: true,
)]
#[AsUiContract(
    summary: 'Plans side by side — price, what is included and one action each — with an optional featured plan.',
    props: [
        new UiProp('title', default: ''),
        new UiProp('lead', default: ''),
        new UiProp('plans', UiPropType::Array, required: true, items: new UiProp('plan', UiPropType::Object, properties: [
            new UiProp('name', required: true),
            new UiProp('price', required: true, description: 'Pre-formatted: "$0", "€29".'),
            new UiProp('period', default: '', description: 'e.g. "per month".'),
            new UiProp('description', default: ''),
            new UiProp('features', UiPropType::Array, default: [], items: new UiProp('feature')),
            new UiProp('action', UiPropType::Object, nullable: true, properties: [
                new UiProp('label', required: true),
                new UiProp('href', required: true, description: 'Through ui_href(): a script URL renders no link.'),
                new UiProp('variant', default: 'solid', values: ['solid', 'soft', 'outline', 'ghost', 'link']),
                new UiProp('icon', default: '', description: 'A Lucide icon name after the label.'),
            ]),
            new UiProp('featured', UiPropType::Boolean, default: false),
            new UiProp('badge', default: '', description: 'A label on the plan, e.g. "Most popular".'),
        ])),
    ],
    examples: [
        new UiExample('default', 'Three plans', [
            'title' => 'Simple pricing',
            'plans' => [
                ['name' => 'Starter', 'price' => '$0', 'period' => 'per month', 'features' => ['1 project', 'Community support'], 'action' => ['label' => 'Start free', 'href' => '#starter', 'variant' => 'outline']],
                ['name' => 'Team', 'price' => '$29', 'period' => 'per month', 'featured' => true, 'badge' => 'Most popular', 'features' => ['10 projects', 'Email support', 'Audit log'], 'action' => ['label' => 'Choose Team', 'href' => '#team']],
                ['name' => 'Enterprise', 'price' => 'Custom', 'features' => ['Unlimited projects', 'SSO', 'Dedicated support'], 'action' => ['label' => 'Contact sales', 'href' => '#sales', 'variant' => 'outline']],
            ],
        ]),
    ],
    previewSafe: true,
)]
final class PricingBlockComponent
{
}
