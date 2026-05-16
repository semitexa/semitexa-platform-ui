<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiPrimitive;

#[AsUiPrimitive(
    name: 'platform.badge',
    ui: 'badge',
    template: '@platform-ui/primitives/runtime/badge.html.twig',
    style: 'platform-ui:css:full',
)]
final class BadgePrimitive
{
}
