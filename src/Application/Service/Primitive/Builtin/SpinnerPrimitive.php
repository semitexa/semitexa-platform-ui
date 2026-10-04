<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiPrimitive;
use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;

#[AsUiPrimitive(
    name: 'platform.spinner',
    ui: 'spinner',
    template: '@platform-ui/primitives/runtime/spinner.html.twig',
    style: 'platform-ui:css:full',
)]
#[AsUiContract(
    summary: 'An indeterminate progress indicator.',
    props: [
        new UiProp('tone', default: 'brand', values: ['brand', 'neutral']),
        new UiProp('size', default: 'md', values: ['sm', 'md', 'lg']),
        new UiProp('label', nullable: true, description: 'Accessible name, e.g. "Loading results".'),
    ],
    examples: [
        new UiExample('default', 'Default', ['label' => 'Loading']),
        new UiExample('large-neutral', 'Large neutral', ['size' => 'lg', 'tone' => 'neutral', 'label' => 'Loading']),
    ],
    previewSafe: true,
)]
final class SpinnerPrimitive
{
}
