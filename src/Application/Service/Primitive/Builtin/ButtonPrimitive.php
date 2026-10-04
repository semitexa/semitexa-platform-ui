<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiPrimitive;
use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;

#[AsUiPrimitive(
    name: 'platform.button',
    ui: 'button',
    template: '@platform-ui/primitives/runtime/button.html.twig',
    style: 'platform-ui:css:full',
)]
#[AsUiContract(
    summary: 'Trigger an action or navigate; renders <a> when href is set.',
    props: [
        new UiProp('text', default: '', description: 'Visible label; the accessible name of an icon-only button.'),
        new UiProp('label', nullable: true, description: 'Alias of text.'),
        new UiProp('href', nullable: true, description: 'Renders a link-button instead of <button>.'),
        new UiProp('type', default: 'button', values: ['button', 'submit', 'reset']),
        new UiProp('variant', default: 'solid', values: ['solid', 'soft', 'outline', 'ghost', 'link'], description: 'How the tone is used.'),
        new UiProp('tone', default: 'brand', values: ['brand', 'neutral', 'info', 'success', 'warning', 'danger'], description: 'Which colour; independent of variant.'),
        new UiProp('size', default: 'md', values: ['sm', 'md', 'lg']),
        new UiProp('shape', nullable: true, values: ['square'], description: 'square = icon-only; text becomes aria-label.'),
        new UiProp('icon', nullable: true, description: 'Leading icon name from the icon registry.'),
        new UiProp('iconEnd', nullable: true, description: 'Trailing icon name.'),
        new UiProp('loading', UiPropType::Boolean, default: false, description: 'Busy: keeps width, shows a ring, aria-busy.'),
        new UiProp('pressed', UiPropType::Boolean, nullable: true, description: 'Toggle button state (aria-pressed).'),
        new UiProp('disabled', UiPropType::Boolean, default: false),
    ],
    examples: [
        new UiExample('variants', 'Primary action', ['text' => 'Save changes']),
        new UiExample('soft', 'Secondary action', ['text' => 'Cancel', 'variant' => 'soft']),
        new UiExample('outline', 'Outline', ['text' => 'Export', 'variant' => 'outline', 'icon' => 'download']),
        new UiExample('ghost', 'Ghost', ['text' => 'Learn more', 'variant' => 'ghost', 'iconEnd' => 'arrow-right']),
        new UiExample('danger', 'Destructive (tone only)', ['text' => 'Delete', 'tone' => 'danger', 'icon' => 'trash']),
        new UiExample('success-soft', 'Soft success', ['text' => 'Approve', 'variant' => 'soft', 'tone' => 'success', 'icon' => 'check']),
        new UiExample('sizes-sm', 'Small', ['text' => 'Small', 'size' => 'sm']),
        new UiExample('sizes-lg', 'Large', ['text' => 'Large', 'size' => 'lg']),
        new UiExample('icon-only', 'Icon only', ['text' => 'Settings', 'shape' => 'square', 'variant' => 'soft', 'icon' => 'settings']),
        new UiExample('loading', 'Loading', ['text' => 'Saving', 'loading' => true]),
        new UiExample('disabled', 'Disabled', ['text' => 'Unavailable', 'disabled' => true]),
    ],
    previewSafe: true,
)]
final class ButtonPrimitive
{
}
