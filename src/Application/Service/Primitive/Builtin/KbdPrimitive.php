<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Attribute\AsUiPrimitive;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;

/**
 * A keyboard shortcut: one key or a combination.
 */
#[AsUiPrimitive(
    name: 'platform.kbd',
    ui: 'kbd',
    template: '@platform-ui/primitives/runtime/kbd.html.twig',
    style: 'platform-ui:css:full',
)]
#[AsUiContract(
    summary: 'A keyboard shortcut: one key or a combination.',
    props: [
        new UiProp('keys', UiPropType::Array, default: [], description: "['Ctrl', 'K']"),
    ],
    examples: [
        new UiExample('combo', 'Command palette', ['keys' => ['Ctrl', 'K']]),
        new UiExample('single', 'Escape', ['keys' => ['Esc']]),
    ],
    previewSafe: true,
)]
final class KbdPrimitive
{
}
