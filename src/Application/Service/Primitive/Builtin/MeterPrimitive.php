<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Attribute\AsUiPrimitive;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;

/**
 * A gauge on native <meter>: the browser colours it by low / high / optimum.
 */
#[AsUiPrimitive(
    name: 'platform.meter',
    ui: 'meter',
    template: '@platform-ui/primitives/runtime/meter.html.twig',
    style: 'platform-ui:css:full',
)]
#[AsUiContract(
    summary: 'A gauge on native <meter>: the browser colours it by low / high / optimum.',
    props: [
        new UiProp('value', UiPropType::Number, default: 0),
        new UiProp('min', UiPropType::Number, default: 0),
        new UiProp('max', UiPropType::Number, default: 1),
        new UiProp('low', UiPropType::Number, default: null, nullable: true),
        new UiProp('high', UiPropType::Number, default: null, nullable: true),
        new UiProp('optimum', UiPropType::Number, default: null, nullable: true),
        new UiProp('label', default: ''),
    ],
    examples: [
        new UiExample('good', 'Disk (good)', ['label' => 'Disk', 'value' => 0.3, 'low' => 0.6, 'high' => 0.85, 'optimum' => 0.1]),
        new UiExample('bad', 'Disk (almost full)', ['label' => 'Disk', 'value' => 0.93, 'low' => 0.6, 'high' => 0.85, 'optimum' => 0.1]),
    ],
    previewSafe: true,
)]
final class MeterPrimitive
{
}
