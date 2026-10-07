<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin\Block;

use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;
use Semitexa\Ssr\Attribute\AsComponent;

/**
 * platform.block-cta — a call to action: a title, a line and the actions,
 * on a brand-tinted or a neutral band. Styling: css/blocks.css.
 */
#[AsComponent(
    name: 'platform.block-cta',
    template: '@platform-ui/components/runtime/blocks/cta.html.twig',
    cacheable: true,
)]
#[AsUiContract(
    summary: 'A call to action on its own band: a title, a line and the actions.',
    props: [
        new UiProp('title', required: true),
        new UiProp('text', default: ''),
        new UiProp('tone', default: 'brand', values: ['brand', 'neutral']),
        new UiProp('actions', UiPropType::Array, default: [], items: new UiProp('action', UiPropType::Object, properties: [
            new UiProp('label', required: true),
            new UiProp('href', required: true, description: 'Through ui_href(): a script URL renders no link.'),
            new UiProp('variant', default: 'solid', values: ['solid', 'soft', 'outline', 'ghost', 'link']),
            new UiProp('icon', default: '', description: 'A Lucide icon name after the label.'),
        ])),
    ],
    examples: [
        new UiExample('default', 'Brand band', ['title' => 'Ready when you are', 'text' => 'Install in a minute, ship today.', 'actions' => [['label' => 'Install', 'href' => '#install']]]),
        new UiExample('neutral', 'Neutral band', ['title' => 'Questions?', 'text' => 'We answer within a day.', 'tone' => 'neutral', 'actions' => [['label' => 'Contact us', 'href' => '#contact', 'variant' => 'outline']]]),
    ],
    previewSafe: true,
)]
final class CtaBlockComponent
{
}
