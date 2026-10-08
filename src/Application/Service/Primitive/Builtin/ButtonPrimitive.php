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
    ],
    previewSafe: true,
)]
final class ButtonPrimitive
{
}
