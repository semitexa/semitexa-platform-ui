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
 *   - $liveOn    — LIVE-ON-EVENTS declaration: a list of invalidation SCOPE
 *                  KEYS this grid refreshes on (e.g. `['ui_playground_leads']`).
 *                  Each entry is the suffix of an `ui.invalidate.{tenant}.{scope}`
 *                  channel — the SAME scope-key string the subscribe side
 *                  ({@see \Semitexa\Ssr\Domain\Model\SubscriptionRecord::$scopeKeys},
 *                  already a list) and the ORM's `#[ResourceKey]` /
 *                  `ResourceChangedEvent::$resourceKey` already speak, so a
 *                  declared scope threads in with ZERO translation. The list is
 *                  an OR: ANY listed scope firing re-runs the held view, and a
 *                  burst of triggers is collapsed to one re-run by the existing
 *                  `RerunCoalescer`. Default `[]` = OFF = a static grid (today's
 *                  behaviour): no scope → no subscription → never re-runs.
 *
 *                  Phasing: this attribute field is the DECLARATION surface
 *                  only. The subscribe (route `liveOn` into
 *                  `SubscriptionRecord::$scopeKeys`) and the publish bridge are
 *                  wired in later phases; declaring `liveOn` here makes the
 *                  scopes available in metadata, nothing goes live yet.
 *
 *                  Structural invariants (live re-run v1 is WINDOWED + SSE
 *                  only) are cross-attribute and so are enforced at boot by
 *                  {@see \Semitexa\PlatformUi\Application\Service\Component\GridComponentMetadataProvider}
 *                  (this ctor cannot see the sibling `#[WithPagination]` /
 *                  the feed `mode`): a non-empty `liveOn` on a cursor/keyset
 *                  grid, or on a {@see self::MODE_PLAIN} feed, BOOT-FAILS there.
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
     * @param list<string> $liveOn
     */
    public function __construct(
        public readonly string $route,
        public readonly ?string $provider = null,
        public readonly array $mutations = [],
        public readonly string $mode = self::MODE_SSE,
        public readonly array $liveOn = [],
    ) {
        if ($mode !== self::MODE_SSE && $mode !== self::MODE_PLAIN) {
            throw new \InvalidArgumentException(sprintf(
                'GridFeed mode must be "%s" or "%s", got "%s".',
                self::MODE_SSE,
                self::MODE_PLAIN,
                $mode,
            ));
        }
        foreach ($liveOn as $scopeKey) {
            if (!is_string($scopeKey) || $scopeKey === '') {
                throw new \InvalidArgumentException(sprintf(
                    'GridFeed liveOn entries must be non-empty scope-key strings '
                    . '(the suffix of an "ui.invalidate.{tenant}.{scope}" channel); got %s.',
                    get_debug_type($scopeKey),
                ));
            }
        }
    }
}
