<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Behavior\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiBehavior;

/**
 * Light / dark / follow-the-system for the visitor, on the unified skin-mode
 * contract: `data-skin-mode` on <html>, `localStorage.semitexa_skin_mode`
 * (absent = follow the system), the same the theme's pre-paint script reads.
 * Binds the radios inside it (a segmented control): light, dark, auto.
 *
 *   <div ui-behavior="skin-mode">{{ primitive('segmented', {name: 'skin_mode', options: …}) }}</div>
 */
#[AsUiBehavior(
    name: 'platform.skin-mode',
    ui: 'skin-mode',
    script: 'platform-ui:js:behaviors',
    a11y: ['native-radios'],
)]
final class SkinModeBehavior {}
