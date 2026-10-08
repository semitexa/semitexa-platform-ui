<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Attribute\AsUiPrimitive;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;

/**
 * A separator, horizontal or vertical, optionally with a label in the middle.
 */
#[AsUiPrimitive(
    name: 'platform.divider',
    ui: 'divider',
    template: '@platform-ui/primitives/runtime/divider.html.twig',
    style: 'platform-ui:css:full',
)]
#[AsUiContract(
    summary: 'A separator, horizontal or vertical, optionally with a label in the middle.',
    props: [
        new UiProp('label', default: ''),
        new UiProp('orientation', default: 'horizontal', values: ['horizontal', 'vertical']),
    ],
    examples: [
        new UiExample('plain', 'Plain', []),
        new UiExample('label', 'With a label', ['label' => 'or']),
    ],
    previewSafe: true,
)]
final class DividerPrimitive
{
}
