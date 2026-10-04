<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Behavior\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiBehavior;
use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Behavior\UiBehaviorOption;
use Semitexa\PlatformUi\Domain\Model\Behavior\UiOptionType;

/**
 * A floating panel anchored to a trigger, positioned via CSS Anchor Positioning
 * (JS fallback). Composes useTogglable + useFloating + useDismiss.
 *
 * A panel of [ui-behavior-item]s is a WAI-ARIA menu button: the runtime adds
 * aria-haspopup/aria-controls on the trigger and role=menu/menuitem on the
 * panel, focuses the first item on open (ArrowUp on the trigger: the last),
 * moves with arrows/Home/End/typeahead, and Tab closes the menu and lets focus
 * move on — a menu is not a focus trap. Esc, outside click and choosing an item
 * close it with focus back on the trigger.
 *
 *   <div ui-behavior="dropdown" ui-dropdown="mode: click; pos: bottom-start">
 *     <button ui="button" ui-behavior-toggle>Menu</button>
 *     <div ui-behavior-content hidden>
 *       <a ui-behavior-item href="/edit">Edit</a>
 *     </div>
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
    a11y: ['aria-expanded', 'aria-haspopup', 'menu-roles', 'esc-dismiss', 'arrow-nav', 'typeahead', 'focus-return'],
)]
#[AsUiContract(
    summary: 'A menu of actions anchored to a trigger.',
    examples: [
        new UiExample('menu', 'Action menu', [], template: '@platform-ui/examples/dropdown.html.twig'),
        new UiExample('end', 'Aligned to the end', ['pos' => 'bottom-end'], template: '@platform-ui/examples/dropdown.html.twig'),
    ],
    previewSafe: true,
)]
final class DropdownBehavior {}
