<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin;

use Semitexa\Ssr\Attribute\AsComponent;
use Semitexa\PlatformUi\Attribute\UiSlot;
use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;

/**
 * platform.pagination — page navigation for a paged list.
 *
 * Data-driven: given `current` and `total` page counts plus an `hrefTemplate`
 * (a URL with a literal `{page}` placeholder), the template renders a
 * semantic `<nav>` with previous/next controls and a windowed set of page
 * links (first/last always shown, a window around the current page, and
 * decorative ellipses for the gaps). The current page is aria-current="page";
 * prev/next at the ends are aria-disabled and unlinked.
 *
 * Props:
 *   - current      — active page (1-based; default 1).
 *   - total        — total number of pages (default 1).
 *   - hrefTemplate — URL with a `{page}` placeholder (default '#').
 *   - window       — page links to show on each side of current (default 1).
 *   - ariaLabel    — accessible name for the <nav> (default "Pagination").
 * Slot:
 *   - summary — optional leading text (e.g. "Showing 1–10 of 200").
 *
 * Styling: css/components.css, `[ui-component="pagination"]`.
 */
#[AsComponent(
    name: 'platform.pagination',
    template: '@platform-ui/components/runtime/pagination.html.twig',
    cacheable: true,
)]
#[UiSlot(name: 'summary', description: 'Optional leading text such as a result-count summary, rendered before the controls.')]
#[AsUiContract(
    summary: 'Move between pages of a long result set.',
    props: [
        new UiProp('current', UiPropType::Integer, default: 1),
        new UiProp('total', UiPropType::Integer, default: 1),
        new UiProp('hrefTemplate', default: '?page={page}', description: 'URL with a literal {page} placeholder.'),
        new UiProp('window', UiPropType::Integer, default: 1, description: 'Pages shown on each side of the current one.'),
        new UiProp('ariaLabel', default: 'Pagination'),
    ],
    examples: [
        new UiExample('middle', 'Middle of a long list', ['current' => 6, 'total' => 12, 'hrefTemplate' => '?page={page}'], ['summary' => 'Showing 51-60 of 118']),
        new UiExample('first', 'First page', ['current' => 1, 'total' => 3, 'hrefTemplate' => '?page={page}']),
    ],
    previewSafe: true,
)]
final class PaginationComponent
{
}
