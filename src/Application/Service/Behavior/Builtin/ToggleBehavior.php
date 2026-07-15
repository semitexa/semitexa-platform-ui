<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Behavior\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiBehavior;
use Semitexa\PlatformUi\Domain\Model\Behavior\UiBehaviorOption;
use Semitexa\PlatformUi\Domain\Model\Behavior\UiOptionType;

/**
 * The generic show/hide behavior — a thin user-facing wrapper over the
 * useTogglable composable. Flips a target's visibility (and the trigger's
 * aria-expanded) on click or hover.
 *
 *   <button ui-behavior="toggle" ui-toggle="target: #panel" aria-expanded="false">More</button>
 *   <div id="panel" hidden> … </div>
 *
 * Passive server declaration; the interaction lives in js/behavior-builtin.js.
 */
#[AsUiBehavior(
    name: 'platform.toggle',
    ui: 'toggle',
    script: 'platform-ui:js:behaviors',
    options: [
        new UiBehaviorOption('target', UiOptionType::Selector, description: 'Selector of the element to show/hide. Falls back to the [ui-behavior-content] child.'),
        new UiBehaviorOption('mode', UiOptionType::Enum, default: 'click', values: ['click', 'hover'], description: 'How the toggle is triggered.'),
        new UiBehaviorOption('openClass', UiOptionType::String, default: 'sx-open', description: 'Class toggled on the target while open.'),
    ],
    a11y: ['aria-expanded'],
)]
final class ToggleBehavior {}
