<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Behavior\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiBehavior;
use Semitexa\PlatformUi\Domain\Model\Behavior\UiBehaviorOption;
use Semitexa\PlatformUi\Domain\Model\Behavior\UiOptionType;

/**
 * A menu button on the native platform: the panel is a popover (top layer,
 * light dismiss, Esc; nested panels stay open as children), the trigger
 * invokes it with commandfor, CSS Anchor Positioning places it.
 *
 * Beyond the dropdown's menu: submenus (a nested `ui-behavior="menu"` whose
 * toggle is an item — ArrowRight opens it, ArrowLeft returns),
 * `menuitemcheckbox` / `menuitemradio` (in a `role="group"`) that keep the menu
 * open, and an `sx:menu:select` event {value, checked, item}. An item that is
 * a component part (data-ui-part) reaches its #[UiOn] handler through HUG.
 *
 *   <div ui-behavior="menu">
 *     <button ui="button" ui-behavior-toggle>Actions</button>
 *     <div ui-behavior-content>
 *       <button ui-behavior-item>Edit</button>
 *       <button ui-behavior-item role="menuitemcheckbox" aria-checked="false">Pinned</button>
 *     </div>
 *   </div>
 */
#[AsUiBehavior(
    name: 'platform.menu',
    ui: 'menu',
    script: 'platform-ui:js:behaviors',
    options: [
        new UiBehaviorOption('pos', UiOptionType::Enum, default: 'bottom-start', values: [
            'bottom-start', 'bottom-end', 'top-start', 'top-end', 'left', 'right',
        ], description: 'Anchor position (a submenu opens to the right).'),
        new UiBehaviorOption('offset', UiOptionType::Number, default: 4, description: 'Gap between trigger and panel, in px.'),
    ],
    a11y: ['aria-expanded', 'aria-haspopup', 'menu-roles', 'esc-dismiss', 'arrow-nav', 'typeahead', 'focus-return'],
)]
final class MenuBehavior {}
