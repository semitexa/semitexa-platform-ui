<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Behavior\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiBehavior;
use Semitexa\PlatformUi\Domain\Model\Behavior\UiBehaviorOption;
use Semitexa\PlatformUi\Domain\Model\Behavior\UiOptionType;

/**
 * Tabbed panels. A [ui-behavior-list] of [ui-behavior-tab]s (role=tab) selects
 * one [ui-behavior-panel] (role=tabpanel) at a time. Roving tabindex + arrow-key
 * navigation, aria-selected/aria-controls wiring.
 *
 *   <div ui-behavior="tabs" ui-tabs="active: 0">
 *     <div role="tablist" ui-behavior-list>
 *       <button ui-behavior-tab role="tab" aria-controls="p1">One</button>
 *       <button ui-behavior-tab role="tab" aria-controls="p2">Two</button>
 *     </div>
 *     <div id="p1" ui-behavior-panel role="tabpanel"> … </div>
 *     <div id="p2" ui-behavior-panel role="tabpanel" hidden> … </div>
 *   </div>
 */
#[AsUiBehavior(
    name: 'platform.tabs',
    ui: 'tabs',
    script: 'platform-ui:js:behaviors',
    options: [
        new UiBehaviorOption('active', UiOptionType::Number, default: 0, description: 'Index of the initially-selected tab.'),
    ],
    a11y: ['aria-selected', 'arrow-nav', 'roving-tabindex'],
)]
final class TabsBehavior {}
