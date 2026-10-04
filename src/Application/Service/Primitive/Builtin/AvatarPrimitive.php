<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiPrimitive;
use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;

#[AsUiPrimitive(
    name: 'platform.avatar',
    ui: 'avatar',
    template: '@platform-ui/primitives/runtime/avatar.html.twig',
    style: 'platform-ui:css:full',
)]
#[AsUiContract(
    summary: 'A person or workspace shown as an image or initials.',
    props: [
        new UiProp('initials', nullable: true),
        new UiProp('text', nullable: true, description: 'Alias of initials.'),
        new UiProp('src', nullable: true, description: 'Image URL; wins over initials.'),
        new UiProp('alt', default: ''),
        new UiProp('label', nullable: true, description: 'Accessible name; without it the avatar is decorative.'),
        new UiProp('size', default: 'md', values: ['sm', 'md', 'lg', 'xl']),
    ],
    examples: [
        new UiExample('initials', 'Initials', ['initials' => 'JD', 'label' => 'Jane Doe']),
        new UiExample('small', 'Small', ['initials' => 'AB', 'size' => 'sm']),
        new UiExample('large', 'Large', ['initials' => 'TH', 'size' => 'lg', 'label' => 'Taras H.']),
    ],
    previewSafe: true,
)]
final class AvatarPrimitive
{
}
