<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;

use Semitexa\PlatformUi\Attribute\AsUiPrimitive;

#[AsUiPrimitive(
    name: 'platform.button',
    ui: 'button',
    template: '@platform-ui/primitives/runtime/button.html.twig',
    style: 'platform-ui:css:full',
)]
#[AsUiContract(
    summary: 'A button, or a link styled as one when it has an href.',
    props: [
        new UiProp('text', required: true),
        new UiProp('href', nullable: true, description: 'A same-site path; makes it a link.'),
        new UiProp('variant', default: 'solid', values: ['solid', 'soft', 'outline', 'ghost', 'link']),
        new UiProp('tone', default: 'neutral', values: ['neutral', 'brand', 'info', 'success', 'warning', 'danger']),
        new UiProp('size', default: 'md', values: ['sm', 'md', 'lg']),
        new UiProp('icon', nullable: true, description: 'A Lucide icon name shown before the text.'),
        new UiProp('disabled', UiPropType::Boolean, default: false),
    ],
    examples: [
        new UiExample('default', 'New order', ['text' => 'New order', 'href' => '/orders?create', 'tone' => 'brand']),
        new UiExample('variants', 'Primary action', ['text' => 'Save changes']),
        new UiExample('soft', 'Secondary action', ['text' => 'Cancel', 'variant' => 'soft']),
        new UiExample('outline', 'Outline', ['text' => 'Export', 'variant' => 'outline', 'icon' => 'download']),
        new UiExample('danger', 'Destructive (tone only)', ['text' => 'Delete', 'tone' => 'danger', 'icon' => 'trash']),
        new UiExample('success-soft', 'Soft success', ['text' => 'Approve', 'variant' => 'soft', 'tone' => 'success', 'icon' => 'check']),
        new UiExample('sizes-sm', 'Small', ['text' => 'Small', 'size' => 'sm']),
        new UiExample('sizes-lg', 'Large', ['text' => 'Large', 'size' => 'lg']),
        new UiExample('disabled', 'Disabled', ['text' => 'Unavailable', 'disabled' => true]),
    ],
    previewSafe: true,
)]
final class ButtonPrimitive
{
}
