<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Attribute\AsUiPrimitive;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;

/**
 * A segmented control: a radio group that looks like one button bar — keyboard and form behaviour stay native.
 */
#[AsUiPrimitive(
    name: 'platform.segmented',
    ui: 'segmented',
    template: '@platform-ui/primitives/runtime/segmented.html.twig',
    style: 'platform-ui:css:full',
)]
#[AsUiContract(
    summary: 'A segmented control: a radio group that looks like one button bar — keyboard and form behaviour stay native.',
    props: [
        new UiProp('name', default: ''),
        new UiProp('options', UiPropType::Array, default: [], description: '[{value, label, icon?}] — icon: a Lucide name shown before the label.'),
        new UiProp('value', default: null, nullable: true),
        new UiProp('label', default: '', description: 'Accessible name of the group.'),
        new UiProp('size', default: 'md', values: ['sm', 'md']),
        new UiProp('iconOnly', UiPropType::Boolean, default: false, description: 'Show only the icons; each label stays as the radio\'s accessible name.'),
        new UiProp('disabled', UiPropType::Boolean, default: false),
        new UiProp('part', default: null, nullable: true, description: 'The component part this control is (data-ui-part), when a field renders it.'),
        new UiProp('aria_invalid', UiPropType::Boolean, default: false, description: 'Marks the group invalid (aria-invalid).'),
        new UiProp('aria_describedby', default: null, nullable: true, description: 'Id of the element that describes the group (help or error text).'),
    ],
    examples: [
        new UiExample('default', 'View', ['name' => 'view', 'label' => 'View', 'value' => 'list', 'options' => [['value' => 'list', 'label' => 'List'], ['value' => 'grid', 'label' => 'Grid'], ['value' => 'map', 'label' => 'Map']]]),
        new UiExample('icons', 'Icons only', ['name' => 'align', 'label' => 'Alignment', 'value' => 'left', 'iconOnly' => true, 'size' => 'sm', 'options' => [['value' => 'left', 'label' => 'Left', 'icon' => 'align-left'], ['value' => 'center', 'label' => 'Centre', 'icon' => 'align-center'], ['value' => 'right', 'label' => 'Right', 'icon' => 'align-right']]]),
    ],
    previewSafe: true,
)]
final class SegmentedPrimitive
{
}
