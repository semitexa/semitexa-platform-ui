<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiPrimitive;

#[AsUiPrimitive(
    name: 'platform.avatar',
    ui: 'avatar',
    template: '@platform-ui/primitives/runtime/avatar.html.twig',
    style: 'platform-ui:css:full',
)]
final class AvatarPrimitive
{
}
