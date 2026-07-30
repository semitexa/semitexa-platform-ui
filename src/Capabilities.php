<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi;

use Semitexa\Core\Attribute\Capability;

/**
 * What this package offers, for the capability catalog.
 *
 * Unlike most packages carrying this class, this one does ship attributes, and
 * each of them already declares its own mechanism. What none of them can say is
 * what the package IS: a reader who has not installed it sees eight mechanisms
 * and no sentence telling them which package to require, or why. The
 * package-level entry sits above the mechanisms rather than duplicating them —
 * they describe what you write, this describes what you install.
 *
 * Nothing reads this at runtime.
 */
#[Capability(
    id: 'ui.kit',
    summary: 'The UI layer: design tokens, primitive components, a declarative behaviour grammar, and LLM-assisted skin generation.',
    useWhen: 'Screens are being assembled repeatedly and should look and behave alike without each one deciding again.',
    avoidWhen: 'A single page with a bespoke design. Tokens and primitives pay for themselves across screens, not on one.',
    replaces: [
        'per-project CSS choosing colours and spacing again at every call site',
        'hand-written JavaScript for behaviours the grammar already declares',
    ],
    seeAlso: 'ui.behavior',
)]
final class Capabilities
{
}
