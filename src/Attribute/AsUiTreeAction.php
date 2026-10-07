<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Attribute;

use Attribute;
use Semitexa\Core\Attribute\Capability;

/**
 * A kind of action a UI tree may name (ep-platform-ai-ui · tk-ai-actions).
 * A tree's actions are only ever these: an agent names a kind and its
 * arguments, never a handler.
 *
 *     #[AsService]
 *     #[AsUiTreeAction(kind: 'navigate')]
 *     final class NavigateTreeAction implements UiTreeActionKindInterface { … }
 */
#[Capability(
    id: 'ui.tree-action',
    summary: 'A kind of action an agent-composed screen may name — a link, a CRUD create or edit — validated for the visitor and resolved by the server.',
    useWhen: 'An agent-composed screen needs a control that does something the application already offers.',
    avoidWhen: 'The action is a write with side effects: give it a CRUD screen or a form, and link to that.',
    replaces: [
        'letting a model put an arbitrary URL or handler name into a screen',
    ],
    seeAlso: 'ui.component-state',
)]
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsUiTreeAction
{
    public function __construct(
        public string $kind,
    ) {
        if (preg_match('/\A[a-z][a-z0-9-]{0,31}\z/', $kind) !== 1) {
            throw new \InvalidArgumentException(sprintf('A tree action kind is a lowercase word, not "%s".', $kind));
        }
    }
}
