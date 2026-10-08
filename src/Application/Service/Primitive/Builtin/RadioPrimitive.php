<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Attribute\AsUiPrimitive;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;

/**
 * A radio button with its label; group several under one name.
 *
 * `part:` puts `data-ui-part` on the control itself, so a component (the
 * field) can address it for validation and patches.
 */
#[AsUiPrimitive(
    name: 'platform.radio',
    ui: 'radio',
    template: '@platform-ui/primitives/runtime/radio.html.twig',
    style: 'platform-ui:css:full',
)]
#[AsUiContract(
    summary: 'A radio button with its label; group several under one name.',
    props: [
        new UiProp('name', default: ''),
        new UiProp('value', default: ''),
        new UiProp('label', default: ''),
        new UiProp('checked', UiPropType::Boolean, default: false),
        new UiProp('disabled', UiPropType::Boolean, default: false),
    ],
    examples: [
        new UiExample('default', 'Option', ['name' => 'plan', 'value' => 'pro', 'label' => 'Pro', 'checked' => true]),
    ],
    previewSafe: true,
)]
final class RadioPrimitive
{
}
