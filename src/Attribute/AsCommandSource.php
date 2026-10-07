<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Attribute;

use Attribute;
use Semitexa\Core\Attribute\Capability;

/**
 * Registers a service that answers the command palette's server search.
 *
 *     #[AsService]
 *     #[ExecutionScoped]            // when it injects request state (auth, tenant)
 *     #[AsCommandSource]
 *     final class ArticleCommands implements UiCommandSourceInterface { … }
 *
 * The palette asks every source for the visitor's query through HUG (a signed,
 * session-bound context); each source is resolved for that request, and a
 * command naming a `permission` the visitor lacks is dropped before it is
 * rendered.
 */
#[Capability(
    id: 'ui.command-source',
    summary: 'A searchable source of commands (records, pages, actions) for the Ctrl+K command palette, filtered by the visitor\'s permissions.',
    useWhen: 'Users should jump to records or actions by typing, from anywhere the palette is on the page.',
    avoidWhen: 'The list is static and short - mark the page\'s own buttons and links with data-ui-command instead.',
    replaces: [
        'a bespoke search endpoint and dropdown per application',
    ],
    seeAlso: 'ui.event-intent',
)]
#[Attribute(Attribute::TARGET_CLASS)]
final class AsCommandSource
{
}
