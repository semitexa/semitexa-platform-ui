<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiPrimitive;
use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;

#[AsUiPrimitive(
    name: 'platform.input',
    ui: 'input',
    template: '@platform-ui/primitives/runtime/input.html.twig',
    style: 'platform-ui:css:full',
)]
#[AsUiContract(
    summary: 'A single-line text field with optional help and error text.',
    props: [
        new UiProp('name', nullable: true),
        new UiProp('id', nullable: true, description: 'Defaults to name.'),
        new UiProp('type', default: 'text', values: ['text', 'email', 'password', 'search', 'url', 'tel', 'number', 'date']),
        new UiProp('value', nullable: true),
        new UiProp('placeholder', nullable: true),
        new UiProp('label', nullable: true, description: 'Accessible name (aria-label) when no <label> wraps the input.'),
        new UiProp('size', default: 'md', values: ['sm', 'md', 'lg']),
        new UiProp('state', nullable: true, values: ['default', 'invalid']),
        new UiProp('help', nullable: true, description: 'Muted help text, linked by aria-describedby.'),
        new UiProp('error', nullable: true, description: 'Error text; sets invalid state and aria-invalid.'),
        new UiProp('required', UiPropType::Boolean, default: false),
        new UiProp('disabled', UiPropType::Boolean, default: false),
        new UiProp('aria_invalid', UiPropType::Boolean, nullable: true),
        new UiProp('aria_describedby', nullable: true),
    ],
    examples: [
        new UiExample('default', 'Default', ['name' => 'email', 'type' => 'email', 'label' => 'Email', 'placeholder' => 'you@example.com']),
        new UiExample('help', 'With help', ['name' => 'handle', 'label' => 'Handle', 'value' => 'jane', 'help' => 'Letters, digits and dashes.']),
        new UiExample('error', 'With error', ['name' => 'email-bad', 'label' => 'Email', 'value' => 'jane@', 'error' => 'Enter a complete email address.']),
        new UiExample('disabled', 'Disabled', ['name' => 'locked', 'label' => 'Workspace', 'value' => 'Read only', 'disabled' => true]),
    ],
    previewSafe: true,
    // A Workbench preview, not yet a block an AI-composed screen may use.
    agent: false,
)]
final class InputPrimitive
{
}
