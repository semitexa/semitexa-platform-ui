<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiPrimitive;
use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;

#[AsUiPrimitive(
    name: 'platform.badge',
    ui: 'badge',
    template: '@platform-ui/primitives/runtime/badge.html.twig',
    style: 'platform-ui:css:full',
)]
#[AsUiContract(
    summary: 'A compact status or category label.',
    props: [
        new UiProp('text', default: ''),
        new UiProp('variant', default: 'soft', values: ['solid', 'soft', 'outline']),
        new UiProp('tone', default: 'neutral', values: ['brand', 'neutral', 'info', 'success', 'warning', 'danger']),
        new UiProp('size', default: 'md', values: ['sm', 'md']),
        new UiProp('dot', UiPropType::Boolean, default: false, description: 'Leading status dot.'),
        new UiProp('icon', nullable: true),
    ],
    examples: [
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
