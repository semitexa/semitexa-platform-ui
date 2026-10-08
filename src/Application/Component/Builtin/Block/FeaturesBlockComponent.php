<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin\Block;

use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;
use Semitexa\Ssr\Attribute\AsComponent;

/**
 * platform.block-features — a grid of features: an icon, a title and a line
 * each, optionally a link. The grid fills its container (container query),
 * not the viewport. Styling: css/blocks.css.
 */
#[AsComponent(
    name: 'platform.block-features',
    template: '@platform-ui/components/runtime/blocks/features.html.twig',
    cacheable: true,
)]
#[AsUiContract(
    summary: 'A grid of features — icon, title and a line each — that reflows to the width it is given.',
    props: [
        new UiProp('title', default: ''),
        new UiProp('lead', default: ''),
        new UiProp('items', UiPropType::Array, required: true, items: new UiProp('feature', UiPropType::Object, properties: [
            new UiProp('title', required: true),
            new UiProp('text', default: ''),
            new UiProp('icon', default: '', description: 'A Lucide icon name.'),
            new UiProp('href', default: '', description: 'Makes the title a link (through ui_href()).'),
        ])),
    ],
    examples: [
        new UiExample('default', 'Three features', [
            'title' => 'Why teams pick it',
            'items' => [
                ['icon' => 'zap', 'title' => 'Fast by default', 'text' => 'Rendered on the server, streamed to the browser.'],
                ['icon' => 'shield-check', 'title' => 'Safe by default', 'text' => 'Signed props, CSP nonces, one transport.'],
                ['icon' => 'accessibility', 'title' => 'Accessible', 'text' => 'Native elements first, ARIA where they stop.'],
            ],
        ]),
    ],
    previewSafe: true,
)]
final class FeaturesBlockComponent
{
}
