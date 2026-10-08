<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Attribute\AsUiPrimitive;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;

/**
 * A progress bar on native <progress>: a value, or indeterminate when it has none.
 */
#[AsUiPrimitive(
    name: 'platform.progress',
    ui: 'progress',
    template: '@platform-ui/primitives/runtime/progress.html.twig',
    style: 'platform-ui:css:full',
)]
#[AsUiContract(
    summary: 'A progress bar on native <progress>: a value, or indeterminate when it has none.',
    props: [
        new UiProp('value', UiPropType::Number, default: null, nullable: true, description: 'Omit for indeterminate.'),
        new UiProp('max', UiPropType::Number, default: 100),
        new UiProp('label', default: '', description: 'Visible caption and accessible name.'),
        new UiProp('showValue', UiPropType::Boolean, default: true),
        new UiProp('tone', default: 'brand', values: ['neutral', 'brand', 'info', 'success', 'warning', 'danger']),
        new UiProp('size', default: 'md', values: ['sm', 'md', 'lg']),
    ],
    examples: [
        new UiExample('value', 'Uploading', ['label' => 'Uploading', 'value' => 64]),
        new UiExample('indeterminate', 'Working', ['label' => 'Working…']),
        new UiExample('success', 'Done', ['label' => 'Storage', 'value' => 100, 'tone' => 'success']),
    ],
    previewSafe: true,
)]
final class ProgressPrimitive
{
}
