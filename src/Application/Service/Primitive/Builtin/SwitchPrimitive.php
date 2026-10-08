<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Attribute\AsUiPrimitive;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;

/**
 * An on/off switch: a checkbox with role=switch, so it submits and validates like one.
 *
 * `part:` puts `data-ui-part` on the control itself, so a component (the
 * field) can address it for validation and patches.
 */
#[AsUiPrimitive(
    name: 'platform.switch',
    ui: 'switch',
    template: '@platform-ui/primitives/runtime/switch.html.twig',
    style: 'platform-ui:css:full',
)]
#[AsUiContract(
    summary: 'An on/off switch: a checkbox with role=switch, so it submits and validates like one.',
    props: [
        new UiProp('name', default: ''),
        new UiProp('value', default: '1'),
        new UiProp('label', default: ''),
        new UiProp('checked', UiPropType::Boolean, default: false),
        new UiProp('disabled', UiPropType::Boolean, default: false),
    ],
    examples: [
        new UiExample('off', 'Off', ['name' => 'alerts', 'label' => 'Email alerts']),
        new UiExample('on', 'On', ['name' => 'dark', 'label' => 'Dark mode', 'checked' => true]),
    ],
    previewSafe: true,
)]
final class SwitchPrimitive
{
}
