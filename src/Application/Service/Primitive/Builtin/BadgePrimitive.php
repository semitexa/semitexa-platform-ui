<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;

use Semitexa\PlatformUi\Attribute\AsUiPrimitive;

#[AsUiPrimitive(
    name: 'platform.badge',
    ui: 'badge',
    template: '@platform-ui/primitives/runtime/badge.html.twig',
    style: 'platform-ui:css:full',
)]
#[AsUiContract(
    summary: 'A short status label.',
    props: [
        new UiProp('text', required: true),
        new UiProp('tone', default: 'neutral', values: ['neutral', 'brand', 'info', 'success', 'warning', 'danger']),
        new UiProp('variant', default: 'soft', values: ['soft', 'solid', 'outline']),
        new UiProp('size', default: 'md', values: ['sm', 'md']),
        new UiProp('dot', UiPropType::Boolean, default: false),
    ],
    examples: [
        new UiExample('default', 'Paid', ['text' => 'Paid', 'tone' => 'success']),
        new UiExample('neutral', 'Neutral', ['text' => 'Draft']),
        new UiExample('success', 'Success', ['text' => 'Active', 'tone' => 'success']),
        new UiExample('warning', 'Warning', ['text' => 'Pending', 'tone' => 'warning']),
        new UiExample('danger-solid', 'Solid danger', ['text' => 'Overdue', 'tone' => 'danger', 'variant' => 'solid']),
        new UiExample('live', 'Outline with dot', ['text' => 'Live', 'tone' => 'success', 'variant' => 'outline', 'dot' => true]),
    ],
    previewSafe: true,
)]
final class BadgePrimitive
{
}
