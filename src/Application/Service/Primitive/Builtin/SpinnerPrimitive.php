<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiPrimitive;

#[AsUiPrimitive(
    name: 'platform.spinner',
    ui: 'spinner',
    template: '@platform-ui/primitives/runtime/spinner.html.twig',
    style: 'platform-ui:css:full',
)]
final class SpinnerPrimitive
{
}
