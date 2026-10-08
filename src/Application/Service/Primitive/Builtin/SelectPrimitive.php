<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Attribute\AsUiPrimitive;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;

/**
 * A native select styled like every other control; where the browser supports `appearance: base-select` the open list is styled too.
 *
 * `part:` puts `data-ui-part` on the control itself, so a component (the
 * field) can address it for validation and patches.
 */
#[AsUiPrimitive(
    name: 'platform.select',
    ui: 'select',
    template: '@platform-ui/primitives/runtime/select.html.twig',
    style: 'platform-ui:css:full',
)]
#[AsUiContract(
    summary: 'A native select styled like every other control; where the browser supports `appearance: base-select` the open list is styled too.',
    props: [
        new UiProp('name', default: ''),
        new UiProp('options', UiPropType::Array, default: [], description: '[{value, label, disabled?}]'),
        new UiProp('value', default: null, nullable: true, description: 'Selected value (a list for multiple).'),
        new UiProp('placeholder', default: '', description: 'An empty first option.'),
        new UiProp('multiple', UiPropType::Boolean, default: false),
        new UiProp('size', default: 'md', values: ['sm', 'md', 'lg']),
        new UiProp('disabled', UiPropType::Boolean, default: false),
    ],
    examples: [
        new UiExample('default', 'Status', ['name' => 'status', 'placeholder' => 'Choose…', 'options' => [['value' => 'draft', 'label' => 'Draft'], ['value' => 'published', 'label' => 'Published']]]),
        new UiExample('selected', 'With a value', ['name' => 'status', 'value' => 'published', 'options' => [['value' => 'draft', 'label' => 'Draft'], ['value' => 'published', 'label' => 'Published']]]),
    ],
    previewSafe: true,
)]
final class SelectPrimitive
{
}
