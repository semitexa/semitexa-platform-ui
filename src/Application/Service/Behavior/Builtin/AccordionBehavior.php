<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Behavior\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiBehavior;
use Semitexa\PlatformUi\Domain\Model\Behavior\UiBehaviorOption;
use Semitexa\PlatformUi\Domain\Model\Behavior\UiOptionType;

/**
 * Collapsible sections. Each [ui-behavior-item] has a [ui-behavior-toggle]
 * header and [ui-behavior-content] body. By default single-open (opening one
 * closes the rest); `multiple: true` allows several open. Composes useTogglable;
 * arrow keys move between headers.
 *
 *   <div ui-behavior="accordion" ui-accordion="multiple: false">
 *     <div ui-behavior-item>
 *       <button ui-behavior-toggle aria-expanded="false">Section</button>
 *       <div ui-behavior-content hidden> … </div>
 *     </div>
 *   </div>
 */
#[AsUiBehavior(
    name: 'platform.accordion',
    ui: 'accordion',
    script: 'platform-ui:js:behaviors',
    options: [
        new UiBehaviorOption('multiple', UiOptionType::Bool, default: false, description: 'Allow more than one section open at once.'),
    ],
    a11y: ['aria-expanded', 'arrow-nav'],
)]
final class AccordionBehavior {}
