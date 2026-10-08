<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Attribute;

use Attribute;
use Semitexa\Core\Attribute\Capability;

/**
 * Registers the server side of a grid's actions by name.
 *
 *     #[AsService]
 *     #[ExecutionScoped]             // when it injects the visitor (auth, tenant)
 *     #[AsGridAction('blog.articles')]
 *     final class ArticleGridActions implements UiGridActionInterface { … }
 *
 * A grid rendered with `actionHandler: 'blog.articles'` and its `serverActions`
 * sends each row, bulk or header action here through HUG; the action list and
 * the handler name are signed into the grid, so the browser can only ask for
 * what the page offered.
 */
#[Capability(
    id: 'ui.grid-action',
    summary: 'Row, bulk and header actions of a platform.grid run on the server through HUG: selection, confirmation, a busy state and a toast, with the actions the page offered signed into the grid.',
    useWhen: 'Rows of a grid are acted on - delete, publish, archive - one at a time, several at once, or all from the header.',
    avoidWhen: 'The action opens a page or a dialog - give the grid a rowActions link instead.',
    replaces: [
        'a POST route per grid action, called with fetch() and a CSRF header',
        'hand-written row checkboxes and a bulk bar',
    ],
    seeAlso: 'ui.form-submit-action',
)]
#[Attribute(Attribute::TARGET_CLASS)]
final class AsGridAction
{
    public function __construct(public string $name) {}
}
