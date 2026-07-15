<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin;

use Semitexa\Ssr\Attribute\AsComponent;
use Semitexa\PlatformUi\Attribute\UiSlot;

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
final class PaginationComponent
{
}
