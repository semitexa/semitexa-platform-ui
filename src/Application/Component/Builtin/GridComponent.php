<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin;

use Semitexa\PlatformUi\Attribute\UiSlot;
use Semitexa\Ssr\Attribute\AsComponent;

/**
 * Minimal interactive read-only grid shell.
 *
 * The grid is **deliberately narrow** — it owns ONLY:
 *
 *   - the root container + its grid-runtime data attributes;
 *   - a hidden refresh-marker patch target;
 *   - a table with caller-provided columns;
 *   - an empty-state block;
 *   - a Next-page link with the no-JS-fallback URL composed from
 *     `pageFallbackUrl` + initial filter state;
 *   - an inline `application/json` bundle the package's
 *     `grid-runtime.js` reads to discover columns + refresh marker.
 *
 * Things the grid **deliberately does NOT** own:
 *
 *   - query semantics (q / action / cursor) — the caller's data
 *     endpoint owns those; the component only knows the data URL;
 *   - the filter form — caller passes it through the `filters` slot;
 *   - authorization — the data endpoint enforces it;
 *   - SSE topic / publisher — the caller wires those project-side;
 *   - row repository, projection, or column types.
 *
 * Frontend runtime: `src/Application/Static/js/grid-runtime.js`
 * discovers every `[data-ui-grid]` root, reads `data-ui-grid-data-url`
 * + `data-ui-grid-sse-url` + the JSON bundle, and orchestrates
 * dynamic loading. The runtime never uses `innerHTML`, `eval`, or
 * the `Function` constructor; rows render through `createElement` +
 * `textContent` only.
 *
 * Caller prop reference (consumed by the template):
 *
 *   - `gridId`            : stable string used by the runtime for
 *                           correlation. Required.
 *   - `instanceId`        : `uci_<16hex>` — the per-render id the
 *                           refresh-marker patch will target.
 *                           Required for SSE refresh.
 *   - `title`             : page-level title above the grid. Optional.
 *   - `description`       : page-level description. Optional.
 *   - `dataUrl`           : JSON endpoint URL. Required.
 *   - `sseUrl`            : SSE channel URL. Optional.
 *   - `refreshMarker`     : refresh-marker `data-ui-patch-target`
 *                           name. Default `grid-refresh-marker`.
 *                           Callers MUST set this to whatever name
 *                           their server-side publisher targets.
 *   - `columns`           : list of `{key, label, style?}` maps —
 *                           defines table header order + row cell
 *                           keys. Required.
 *   - `initialRows`       : list of row maps from the server. The
 *                           template renders these into the
 *                           server-rendered fallback `<tbody>` so
 *                           the page is useful without JS.
 *   - `initialPagination` : `{limit, hasMore, nextCursor}` for the
 *                           server-rendered Next-link fallback.
 *   - `initialQuery`      : initial filter `q` (rendered into the
 *                           fallback Next-link href).
 *   - `initialAction`     : initial filter `action` (rendered into
 *                           the fallback Next-link href).
 *   - `initialSort`       : optional active sort token (e.g.
 *                           `submittedAt_desc`). Server-resolved
 *                           from the caller's allow-list — the
 *                           component does NOT validate it. Used
 *                           to:
 *                             - render the active column's `aria-sort`
 *                               + the no-JS toggle href,
 *                             - seed `state.sort` in the runtime,
 *                             - preserve the active sort in the
 *                               Next-link fallback href.
 *                           Empty / null → no sortable-column UI is
 *                           highlighted; the caller may still mark
 *                           individual columns as sortable.
 *   - `sortParam`         : optional name of the URL parameter the
 *                           runtime appends when reloading. Default
 *                           `sort`. The caller's data endpoint MUST
 *                           accept this same name.
 *   - `pageFallbackUrl`   : route the no-JS Next link should point
 *                           at (the caller's HTML page route).
 *                           Required if `initialPagination.hasMore`.
 *   - `emptyMessage`      : empty-state copy.
 *
 * Sortable columns: a column map MAY include `sortAsc` + `sortDesc`
 * keys (allow-listed sort tokens for that field). When BOTH are
 * present, the template renders the header label as an `<a
 * data-ui-grid-sort>` link whose `href` toggles the sort against
 * the current `initialSort`. The runtime intercepts clicks, sets
 * `state.sort`, clears the cursor, and reloads. Columns without
 * `sortAsc`/`sortDesc` render as plain header cells (the default
 * for the demo-submissions grid, which is not in scope this slice).
 *
 * The class itself is intentionally a tiny metadata holder — no
 * lifecycle methods, no event handlers. The render contract lives
 * entirely in the Twig template; PHP just provides the
 * `#[AsComponent]` discovery hook and the slot declarations.
 */
#[AsComponent(
    name: 'platform.grid',
    template: '@platform-ui/components/runtime/grid.html.twig',
    cacheable: true,
)]
#[UiSlot(
    name: 'filters',
    description: 'Caller-provided filter form. Must include a `<form data-ui-grid-form method="get" action="<page-url>">` whose inputs (name="q" / name="action" / name="limit") match the caller\'s data endpoint contract. The grid runtime intercepts submit and reissues a JSON fetch instead of a full reload.',
)]
#[UiSlot(
    name: 'filterState',
    description: 'Optional active-filter copy (e.g. "Filtering by q = …"). Must include the `data-ui-grid-filter-state` root + `data-ui-grid-filter-state-q` / `data-ui-grid-filter-state-action` text nodes so the runtime can update them after a reload.',
)]
#[UiSlot(
    name: 'warning',
    description: 'Optional production-warning callout rendered above the grid (e.g. "Diagnostic playground endpoint").',
)]
#[UiSlot(
    name: 'footer',
    description: 'Optional caller-owned footer rendered below the grid (e.g. back-links, deliberate-exclusions note).',
)]
final class GridComponent
{
    /**
     * The default refresh-marker target name. Callers that publish
     * SSE refresh signals MUST mint patches targeting this same
     * name (or whatever they override via the `refreshMarker` prop).
     */
    public const DEFAULT_REFRESH_MARKER_NAME = 'grid-refresh-marker';
}
