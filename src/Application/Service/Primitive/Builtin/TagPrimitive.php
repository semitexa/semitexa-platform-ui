<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Attribute\AsUiPrimitive;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;

/**
 * A label chip; `removable` adds a remove button, and with `name` it carries a hidden form value that goes with it.
 */
#[AsUiPrimitive(
    name: 'platform.tag',
    ui: 'tag',
    template: '@platform-ui/primitives/runtime/tag.html.twig',
    style: 'platform-ui:css:full',
)]
#[AsUiContract(
    summary: 'A label chip; `removable` adds a remove button, and with `name` it carries a hidden form value that goes with it.',
    props: [
        new UiProp('text', default: ''),
        new UiProp('tone', default: 'neutral', values: ['neutral', 'brand', 'info', 'success', 'warning', 'danger']),
        new UiProp('removable', UiPropType::Boolean, default: false),
        new UiProp('name', default: '', description: 'Submit the tag as a form value (name[]).'),
        new UiProp('value', default: '', description: 'The submitted value (defaults to the text).'),
    ],
    examples: [
        new UiExample('default', 'PHP', ['text' => 'PHP']),
        new UiExample('removable', 'Removable', ['text' => 'Swoole', 'tone' => 'brand', 'removable' => true]),
    ],
    previewSafe: true,
)]
final class TagPrimitive
{
}
