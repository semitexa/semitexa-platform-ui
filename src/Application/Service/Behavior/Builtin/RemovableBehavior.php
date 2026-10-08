<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Behavior\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiBehavior;

/**
 * Something the user can remove — a tag, a dismissible note. A click on a
 * `[ui-removable-trigger]` inside dispatches a cancelable
 * `ui-removable:remove` event; unless a listener prevents it, the element is
 * removed (with it any hidden form value it carried) and focus moves to its
 * next removable sibling, so a keyboard user is not dropped on <body>.
 *
 *   <span ui="tag" ui-behavior="removable">PHP <button ui-removable-trigger aria-label="Remove PHP">×</button></span>
 */
#[AsUiBehavior(
    name: 'platform.removable',
    ui: 'removable',
    script: 'platform-ui:js:behaviors',
    a11y: ['focus-management'],
)]
final class RemovableBehavior {}
