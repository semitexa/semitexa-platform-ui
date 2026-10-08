<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Attribute\AsUiPrimitive;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;

/**
 * A loading placeholder: text lines, a circle or a block, shimmering unless motion is reduced.
 */
#[AsUiPrimitive(
    name: 'platform.skeleton',
    ui: 'skeleton',
    template: '@platform-ui/primitives/runtime/skeleton.html.twig',
    style: 'platform-ui:css:full',
)]
#[AsUiContract(
    summary: 'A loading placeholder: text lines, a circle or a block, shimmering unless motion is reduced.',
    props: [
        new UiProp('shape', default: 'text', values: ['text', 'circle', 'rect']),
        new UiProp('lines', UiPropType::Integer, default: 1, description: 'Text lines (shape text).'),
        new UiProp('width', default: '', description: 'Any CSS length.'),
        new UiProp('height', default: '', description: 'Any CSS length.'),
    ],
    examples: [
        new UiExample('text', 'Three lines', ['lines' => 3]),
        new UiExample('circle', 'Avatar', ['shape' => 'circle', 'width' => '3rem']),
        new UiExample('rect', 'Media', ['shape' => 'rect', 'height' => '8rem']),
    ],
    previewSafe: true,
)]
final class SkeletonPrimitive
{
}
