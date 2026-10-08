<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin\Block;

use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;
use Semitexa\Ssr\Attribute\AsComponent;

/**
 * platform.block-faq — questions and answers as native <details>. With
 * `exclusive` (the default) they share a `name`, so the browser keeps one
 * open at a time; without JavaScript, and searchable with find-in-page.
 * Two FAQs on one page need different `group`s. Styling: css/blocks.css.
 */
#[AsComponent(
    name: 'platform.block-faq',
    template: '@platform-ui/components/runtime/blocks/faq.html.twig',
    cacheable: true,
)]
#[AsUiContract(
    summary: 'Questions and answers on native <details name>: one open at a time, no JavaScript, found by find-in-page.',
    props: [
        new UiProp('title', default: 'Frequently asked questions'),
        new UiProp('lead', default: ''),
        new UiProp('items', UiPropType::Array, required: true, items: new UiProp('entry', UiPropType::Object, properties: [
            new UiProp('question', required: true),
            new UiProp('answer', required: true, description: 'Plain text; blank lines start a new paragraph.'),
        ])),
        new UiProp('exclusive', UiPropType::Boolean, default: true, description: 'One answer open at a time.'),
        new UiProp('group', default: 'faq', description: 'The shared <details name>; give a second FAQ on the page its own.'),
        new UiProp('open', UiPropType::Integer, default: 0, description: 'Which entry starts open (1-based); 0 = none.'),
    ],
    examples: [
        new UiExample('default', 'Three questions', [
            'items' => [
                ['question' => 'Does it need JavaScript?', 'answer' => 'No. The answers are native <details> elements.'],
                ['question' => 'Can two be open?', 'answer' => 'Only with exclusive: false.'],
                ['question' => 'Is it searchable?', 'answer' => 'Yes — find-in-page opens the matching answer.'],
            ],
            'open' => 1,
        ]),
    ],
    previewSafe: true,
)]
final class FaqBlockComponent
{
}
