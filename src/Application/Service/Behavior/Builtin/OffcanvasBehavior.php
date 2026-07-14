<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Behavior\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiBehavior;
use Semitexa\PlatformUi\Domain\Model\Behavior\UiBehaviorOption;
use Semitexa\PlatformUi\Domain\Model\Behavior\UiOptionType;

/**
 * A slide-in side panel. Opened by [ui-behavior-open="#id"] triggers; composes
 * useFocusTrap + useDismiss + useScrollLock and shows a backdrop. Slides from the
 * inline start or end.
 *
 *   <button ui-behavior-open="#nav">Menu</button>
 *   <aside id="nav" ui-behavior="offcanvas" ui-offcanvas="side: start" hidden>
 *     … <button ui-behavior-dismiss>Close</button>
 *   </aside>
 */
#[AsUiBehavior(
    name: 'platform.offcanvas',
    ui: 'offcanvas',
    script: 'platform-ui:js:behaviors',
    options: [
        new UiBehaviorOption('side', UiOptionType::Enum, default: 'start', values: ['start', 'end'], description: 'Inline side the panel slides from (RTL-correct).'),
        new UiBehaviorOption('bgClose', UiOptionType::Bool, default: true, description: 'Close when the backdrop is clicked.'),
    ],
    a11y: ['focus-trap', 'esc-dismiss', 'scroll-lock'],
)]
final class OffcanvasBehavior {}
