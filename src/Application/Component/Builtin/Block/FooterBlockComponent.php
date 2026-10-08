<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin\Block;

use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;
use Semitexa\Ssr\Attribute\AsComponent;

/**
 * platform.block-footer — the site footer: the brand and a tagline, columns
 * of links, and a bottom row with the legal line and its own links.
 * A <footer> with one labelled <nav> per column. It is the page's contentinfo
 * landmark only outside <main>/<article>/<section> — put it after the layout's
 * main, not inside it. Styling: css/blocks.css.
 */
#[AsComponent(
    name: 'platform.block-footer',
    template: '@platform-ui/components/runtime/blocks/footer.html.twig',
    cacheable: true,
)]
#[AsUiContract(
    summary: 'The site footer: brand and tagline, columns of links, and a legal row.',
    props: [
        new UiProp('brand', default: ''),
        new UiProp('tagline', default: ''),
        new UiProp('columns', UiPropType::Array, default: [], items: new UiProp('column', UiPropType::Object, properties: [
            new UiProp('title', required: true),
            new UiProp('links', UiPropType::Array, default: [], items: new UiProp('link', UiPropType::Object, properties: [new UiProp('label', required: true), new UiProp('href', required: true)])),
        ])),
        new UiProp('legal', default: '', description: 'e.g. "© 2026 Example Ltd."'),
        new UiProp('links', UiPropType::Array, default: [], items: new UiProp('link', UiPropType::Object, properties: [new UiProp('label', required: true), new UiProp('href', required: true)]), description: 'The bottom row: privacy, terms…'),
    ],
    examples: [
        new UiExample('default', 'Two columns', [
            'brand' => 'Semitexa',
            'tagline' => 'Server-first UI for PHP.',
            'columns' => [
                ['title' => 'Product', 'links' => [['label' => 'Components', 'href' => '#components'], ['label' => 'Pricing', 'href' => '#pricing']]],
                ['title' => 'Company', 'links' => [['label' => 'About', 'href' => '#about'], ['label' => 'Contact', 'href' => '#contact']]],
            ],
            'legal' => '© 2026 Semitexa',
            'links' => [['label' => 'Privacy', 'href' => '#privacy'], ['label' => 'Terms', 'href' => '#terms']],
        ]),
    ],
    previewSafe: true,
)]
final class FooterBlockComponent
{
}
