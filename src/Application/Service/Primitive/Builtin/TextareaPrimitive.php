<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Attribute\AsUiPrimitive;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;

/**
 * Multi-line text that looks and validates like the input.
 *
 * `part:` puts `data-ui-part` on the control itself, so a component (the
 * field) can address it for validation and patches.
 */
#[AsUiPrimitive(
    name: 'platform.textarea',
    ui: 'textarea',
    template: '@platform-ui/primitives/runtime/textarea.html.twig',
    style: 'platform-ui:css:full',
)]
#[AsUiContract(
    summary: 'Multi-line text that looks and validates like the input.',
    props: [
        new UiProp('name', default: ''),
        new UiProp('value', default: ''),
        new UiProp('rows', UiPropType::Integer, default: 4),
        new UiProp('placeholder', default: ''),
        new UiProp('size', default: 'md', values: ['sm', 'md', 'lg']),
        new UiProp('disabled', UiPropType::Boolean, default: false),
    ],
    examples: [
        new UiExample('default', 'Message', ['name' => 'message', 'placeholder' => 'Write a few lines…']),
    ],
    previewSafe: true,
)]
final class TextareaPrimitive
{
}
