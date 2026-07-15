<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Behavior\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiBehavior;
use Semitexa\PlatformUi\Domain\Model\Behavior\UiBehaviorOption;
use Semitexa\PlatformUi\Domain\Model\Behavior\UiOptionType;

/**
 * A floating panel anchored to a trigger, positioned via CSS Anchor Positioning
 * (JS fallback). Composes useTogglable + useFloating + useFocusTrap + useDismiss,
 * so it gets a real focus trap, Esc/outside dismissal, and aria-expanded for
 * free — the composables fix UIkit's uneven a11y once, for every behavior.
 *
 *   <div ui-behavior="dropdown" ui-dropdown="mode: click; pos: bottom-start">
 *     <button ui="button" ui-behavior-toggle aria-expanded="false">Menu</button>
 *     <div ui-behavior-content hidden> … </div>
 *   </div>
 *
 * Passive server declaration; the interaction lives in js/behavior-builtin.js.
 */
#[AsUiBehavior(
    name: 'platform.dropdown',
    ui: 'dropdown',
    script: 'platform-ui:js:behaviors',
    options: [
        new UiBehaviorOption('mode', UiOptionType::Enum, default: 'click', values: ['click', 'hover'], description: 'Open on click or hover.'),
        new UiBehaviorOption('pos', UiOptionType::Enum, default: 'bottom-start', values: [
            'bottom-start', 'bottom-end', 'top-start', 'top-end', 'left', 'right',
        ], description: 'Anchor position (logical sides, RTL-correct).'),
        new UiBehaviorOption('offset', UiOptionType::Number, default: 4, description: 'Gap between trigger and panel, in px.'),
        new UiBehaviorOption('flip', UiOptionType::Bool, default: true, description: 'Flip to the opposite side when it would clip the viewport.'),
    ],
    a11y: ['aria-expanded', 'focus-trap', 'esc-dismiss', 'arrow-nav'],
)]
final class DropdownBehavior {}
