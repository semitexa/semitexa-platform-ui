<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\Ssr\Attribute\AsComponent;

/**
 * The visitor's appearance: light, dark or follow the system — kept in this
 * browser (the skin-mode contract), applied at once, before paint on the next
 * page. No server round trip: it is a per-viewer preference.
 */
#[AsComponent(
    name: 'platform.appearance-settings',
    template: '@platform-ui/components/runtime/appearance-settings.html.twig',
)]
#[AsUiContract(
    summary: 'Light, dark or follow the system — a segmented control on the skin-mode contract, applied at once and remembered in this browser.',
    props: [
        new UiProp('title', default: 'Theme'),
        new UiProp('description', default: 'Choose how the interface looks on this device.'),
    ],
    previewSafe: true,
)]
final class AppearanceSettingsComponent
{
}
