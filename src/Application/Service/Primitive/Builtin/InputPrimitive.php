<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiPrimitive;

#[AsUiPrimitive(
    name: 'platform.input',
    ui: 'input',
    template: '@platform-ui/primitives/runtime/input.html.twig',
    style: 'platform-ui:css:full',
)]
final class InputPrimitive
{
}
