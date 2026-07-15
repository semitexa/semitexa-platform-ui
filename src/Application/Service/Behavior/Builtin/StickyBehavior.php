<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Behavior\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiBehavior;
use Semitexa\PlatformUi\Domain\Model\Behavior\UiBehaviorOption;
use Semitexa\PlatformUi\Domain\Model\Behavior\UiOptionType;

/**
 * Pins an element on scroll using native CSS `position: sticky`; the behavior
 * only adds a `sx-stuck` class (via an IntersectionObserver sentinel) so CSS can
 * style the stuck state (e.g. a shadow). Native-first, same spirit as the
 * dropdown's CSS Anchor Positioning and the modal's <dialog>.
 *
 *   <header ui-behavior="sticky" ui-sticky="offset: 0"> … </header>
 */
#[AsUiBehavior(
    name: 'platform.sticky',
    ui: 'sticky',
    script: 'platform-ui:js:behaviors',
    options: [
        new UiBehaviorOption('offset', UiOptionType::Number, default: 0, description: 'Sticky top offset in px.'),
    ],
    a11y: [],
)]
final class StickyBehavior {}
