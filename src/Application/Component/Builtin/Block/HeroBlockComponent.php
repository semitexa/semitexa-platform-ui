<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin\Block;

use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Attribute\UiSlot;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;
use Semitexa\Ssr\Attribute\AsComponent;

/**
 * platform.block-hero — the page's opening statement: an eyebrow, a headline,
 * a lead and up to a few actions, beside optional media when the block is wide.
 *
 * Page blocks are data-driven (a page builder or CMS can hand them props), so
 * every action href goes through ui_href(). Styling: css/blocks.css.
 */
#[AsComponent(
    name: 'platform.block-hero',
    template: '@platform-ui/components/runtime/blocks/hero.html.twig',
    cacheable: true,
)]
#[UiSlot(name: 'media', description: 'An image, video or illustration; beside the text on a wide block, below it on a narrow one.')]
#[AsUiContract(
    summary: 'The opening statement of a page: eyebrow, headline, lead and actions, with optional media beside it.',
    props: [
        new UiProp('title', required: true, description: 'The headline (the page\'s h1 unless headingLevel says otherwise).'),
        new UiProp('eyebrow', default: '', description: 'A short line above the headline.'),
        new UiProp('lead', default: ''),
        new UiProp('align', default: 'start', values: ['start', 'center']),
        new UiProp('headingLevel', UiPropType::Integer, default: 1, values: [1, 2]),
        new UiProp('actions', UiPropType::Array, default: [], items: new UiProp('action', UiPropType::Object, properties: [
            new UiProp('label', required: true),
            new UiProp('href', required: true, description: 'Through ui_href(): a script URL renders no link.'),
            new UiProp('variant', default: 'solid', values: ['solid', 'soft', 'outline', 'ghost', 'link']),
            new UiProp('icon', default: '', description: 'A Lucide icon name after the label.'),
        ])),
    ],
    examples: [
        new UiExample('default', 'Product launch', [
            'eyebrow' => 'New in 2026.10',
            'title' => 'Ship the interface, not the plumbing',
            'lead' => 'Server-rendered components, native popovers and one transport for every interaction.',
            'actions' => [['label' => 'Get started', 'href' => '#start'], ['label' => 'Read the docs', 'href' => '#docs', 'variant' => 'outline']],
        ]),
        new UiExample('centered', 'Centred', ['title' => 'One place for your whole team', 'lead' => 'Plan, build and ship together.', 'align' => 'center']),
    ],
    previewSafe: true,
)]
final class HeroBlockComponent
{
}
