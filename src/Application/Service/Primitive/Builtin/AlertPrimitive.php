<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiPrimitive;
use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;

#[AsUiPrimitive(
    name: 'platform.alert',
    ui: 'alert',
    template: '@platform-ui/primitives/runtime/alert.html.twig',
    style: 'platform-ui:css:full',
)]
#[AsUiContract(
    summary: 'An inline message about the state of the page or a task.',
    props: [
        new UiProp('text', default: ''),
        new UiProp('title', nullable: true),
        new UiProp('tone', default: 'info', values: ['neutral', 'info', 'success', 'warning', 'danger'], description: 'warning/danger announce as role=alert.'),
        new UiProp('variant', default: 'soft', values: ['soft', 'outline', 'solid']),
        new UiProp('icon', nullable: true, description: 'Overrides the per-tone icon.'),
        new UiProp('role', nullable: true, values: ['status', 'alert'], description: 'Overrides the tone-derived role.'),
    ],
    examples: [
        new UiExample('info', 'Info', ['title' => 'Heads up', 'text' => 'Your trial ends in 3 days.']),
        new UiExample('success', 'Success', ['tone' => 'success', 'title' => 'Saved', 'text' => 'Your changes are live.']),
        new UiExample('warning', 'Warning', ['tone' => 'warning', 'title' => 'Storage almost full', 'text' => 'You have used 92% of your quota.']),
        new UiExample('danger', 'Danger', ['tone' => 'danger', 'title' => 'Payment failed', 'text' => 'Update your card to keep the workspace active.']),
        new UiExample('solid', 'Solid', ['tone' => 'success', 'variant' => 'solid', 'text' => 'Deployment finished.']),
    ],
    previewSafe: true,
)]
final class AlertPrimitive
{
}
