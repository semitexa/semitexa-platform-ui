<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Behavior\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiBehavior;
use Semitexa\PlatformUi\Domain\Model\Behavior\UiBehaviorOption;
use Semitexa\PlatformUi\Domain\Model\Behavior\UiOptionType;

/**
 * Adds a class when the element scrolls into view (IntersectionObserver via the
 * useInView composable) — for reveal-on-enter animations. `repeat: true`
 * re-triggers on every entry; otherwise it fires once.
 *
 *   <section ui-behavior="scrollspy" ui-scrollspy="cls: sx-inview; repeat: false"> … </section>
 */
#[AsUiBehavior(
    name: 'platform.scrollspy',
    ui: 'scrollspy',
    script: 'platform-ui:js:behaviors',
    options: [
        new UiBehaviorOption('cls', UiOptionType::String, default: 'sx-inview', description: 'Class toggled when in view.'),
        new UiBehaviorOption('repeat', UiOptionType::Bool, default: false, description: 'Re-trigger on every entry (else once).'),
        new UiBehaviorOption('threshold', UiOptionType::Number, default: 0, description: 'IntersectionObserver visibility threshold 0..1.'),
    ],
    a11y: [],
)]
final class ScrollspyBehavior {}
