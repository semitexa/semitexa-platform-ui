<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiPrimitive;

#[AsUiPrimitive(
    name: 'platform.alert',
    ui: 'alert',
    template: '@platform-ui/primitives/runtime/alert.html.twig',
    style: 'platform-ui:css:full',
)]
final class AlertPrimitive
{
}
