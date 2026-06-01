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
 * The feed comes in two modes (the runtime reads `feed.mode` from the
 * bundle and picks its transport accordingly):
 *
 *   - {@see self::MODE_SSE} (default) — the held-open one-URL stream
 *     described above: a single persistent `EventSource`, server-minted
 *     id adoption, one-URL `X-Semitexa-Stream-Rehydrate` re-hydrate, and
 *     a pulling fallback when SSE is unavailable. This is the leads grid.
 *   - {@see self::MODE_PLAIN} — a PLAIN pull feed: NO persistent
 *     `EventSource` and NO OPTIONS handshake. The runtime fetches the
 *     feed route as classic JSON (the same `UiGridDataResponse` envelope)
 *     on load and re-fetches on each view change. This fits a static /
 *     finite grid whose endpoint is plain GET JSON (no held-open SSE
 *     route), e.g. the synthetic component-inventory grid. Using the SSE
 *     init path here would be wrong — OPTIONS on a GET-only JSON endpoint
 *     fails and the runtime would optimistically open an `EventSource`
 *     against a non-streaming route.
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
    /** Held-open SSE feed (the one-URL stream); the default. */
    public const MODE_SSE = 'sse';

    /** Plain pull feed — classic JSON GET, no EventSource, no OPTIONS. */
    public const MODE_PLAIN = 'plain';

    /**
     * @param class-string|null $provider
     * @param list<array{label: string, route: string, method?: string}> $mutations
     * @param self::MODE_* $mode
     */
    public function __construct(
        public readonly string $route,
        public readonly ?string $provider = null,
        public readonly array $mutations = [],
        public readonly string $mode = self::MODE_SSE,
    ) {
        if ($mode !== self::MODE_SSE && $mode !== self::MODE_PLAIN) {
            throw new \InvalidArgumentException(sprintf(
                'GridFeed mode must be "%s" or "%s", got "%s".',
                self::MODE_SSE,
                self::MODE_PLAIN,
                $mode,
            ));
        }
    }
}
