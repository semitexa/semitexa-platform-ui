<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Attribute;

use Attribute;

/**
 * Declares a grid component's DATA FEED — the held-open SSE endpoint that
 * supplies its rows.
 *
 * Placed on a `#[AsComponent]` grid class beside `#[AsColumn]` / `#[AsFilter]`
 * / `#[WithPagination]`. Where those declare the SHAPE of the grid (which
 * columns, which filters, how it paginates), `#[GridFeed]` declares its
 * TRANSPORT: the one URL that holds a persistent `EventSource` open and
 * receives `X-Semitexa-Stream-Rehydrate` view-change commands (the "one-URL
 * grid" contract). A grid that carries this attribute is driven by the
 * shared `grid-runtime.js` feed runtime instead of the canonical
 * `/__ui/dispatch` + page-session KISS model.
 *
 * Read at boot by {@see \Semitexa\PlatformUi\Application\Service\Component\GridComponentMetadataProvider},
 * which reflects it off the component class and emits a `gridFeed` prop that
 * the `platform.grid` template bakes into the runtime's JSON bundle. The
 * runtime reads the feed route + columns from that bundle — there are no
 * grid-specific literals in the runtime, so a second grid needs only a class
 * + attributes.
 *
 *   - $route     — the held-open SSE feed endpoint. GET opens the persistent
 *                  stream (the server mints + announces its id via the first
 *                  `ui.stream.id` event); POST + `X-Semitexa-Stream-Rehydrate`
 *                  carries a view-change command on the SAME url. Both verbs
 *                  bind one route (one-URL grid).
 *   - $provider  — optional row data-provider FQCN (the server-side row
 *                  source the feed handler reads). Documentary at the
 *                  declaration level; the feed endpoint itself resolves rows.
 *   - $mutations — optional declared mutation buttons the grid renders (e.g.
 *                  "+ Add a demo lead"). Each entry is
 *                  `{label, route, method?}`; clicking POSTs to the mutation
 *                  route and — in SSE mode — the resulting row arrives live on
 *                  the open feed (in pulling mode the runtime re-fetches once).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class GridFeed
{
    /**
     * @param class-string|null $provider
     * @param list<array{label: string, route: string, method?: string}> $mutations
     */
    public function __construct(
        public readonly string $route,
        public readonly ?string $provider = null,
        public readonly array $mutations = [],
    ) {}
}
