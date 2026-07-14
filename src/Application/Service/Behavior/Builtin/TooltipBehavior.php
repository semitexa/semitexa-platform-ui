<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Behavior\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiBehavior;
use Semitexa\PlatformUi\Domain\Model\Behavior\UiBehaviorOption;
use Semitexa\PlatformUi\Domain\Model\Behavior\UiOptionType;

/**
 * A hover/focus popup label positioned via the same useFloating engine as the
 * dropdown (CSS Anchor Positioning + fallback). The label comes from the `title`
 * option (or a [ui-behavior-content] child); shown after a short delay, wired
 * via aria-describedby, dismissed on leave/blur/Escape.
 *
 *   <button ui-behavior="tooltip" ui-tooltip="title: Save changes; pos: top">💾</button>
 */
#[AsUiBehavior(
    name: 'platform.tooltip',
    ui: 'tooltip',
    script: 'platform-ui:js:behaviors',
    options: [
        new UiBehaviorOption('title', UiOptionType::String, description: 'Tooltip text (when no [ui-behavior-content] child is present).'),
        new UiBehaviorOption('pos', UiOptionType::Enum, default: 'top', values: ['top', 'bottom', 'left', 'right'], description: 'Anchor side.'),
        new UiBehaviorOption('delay', UiOptionType::Number, default: 100, description: 'Show delay in ms.'),
    ],
    a11y: ['aria-describedby'],
)]
final class TooltipBehavior {}
