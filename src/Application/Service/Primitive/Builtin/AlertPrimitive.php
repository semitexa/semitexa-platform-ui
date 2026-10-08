<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;

use Semitexa\PlatformUi\Attribute\AsUiPrimitive;

#[AsUiPrimitive(
    name: 'platform.alert',
    ui: 'alert',
    template: '@platform-ui/primitives/runtime/alert.html.twig',
    style: 'platform-ui:css:full',
)]
#[AsUiContract(
    summary: 'A message in a box, with a tone that says what kind: info, success, warning or danger.',
    props: [
        new UiProp('text', required: true),
        new UiProp('title', nullable: true),
        new UiProp('tone', default: 'info', values: ['neutral', 'info', 'success', 'warning', 'danger']),
        new UiProp('variant', default: 'soft', values: ['soft', 'outline', 'solid']),
    ],
    examples: [
        new UiExample('default', 'Saved', ['title' => 'Saved', 'text' => 'Your changes are live.', 'tone' => 'success']),
    ],
    previewSafe: true,
)]
final class AlertPrimitive
{
}
