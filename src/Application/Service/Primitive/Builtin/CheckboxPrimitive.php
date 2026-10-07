<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Attribute\AsUiPrimitive;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;

/**
 * A checkbox with its label, drawn from tokens (light and dark), keyboard and screen-reader native.
 *
 * `part:` puts `data-ui-part` on the control itself, so a component (the
 * field) can address it for validation and patches.
 */
#[AsUiPrimitive(
    name: 'platform.checkbox',
    ui: 'checkbox',
    template: '@platform-ui/primitives/runtime/checkbox.html.twig',
    style: 'platform-ui:css:full',
)]
#[AsUiContract(
    summary: 'A checkbox with its label, drawn from tokens (light and dark), keyboard and screen-reader native.',
    props: [
        new UiProp('name', default: ''),
        new UiProp('value', default: '1'),
        new UiProp('label', default: ''),
        new UiProp('checked', UiPropType::Boolean, default: false),
        new UiProp('disabled', UiPropType::Boolean, default: false),
    ],
    examples: [
        new UiExample('default', 'Unchecked', ['name' => 'terms', 'label' => 'I accept the terms']),
        new UiExample('checked', 'Checked', ['name' => 'news', 'label' => 'Send me news', 'checked' => true]),
    ],
    previewSafe: true,
)]
final class CheckboxPrimitive
{
}
