/**
 * Platform UI grid runtime — generic interactive shell driver.
 *
 * Discovers every element with `[data-ui-grid]` on
 * DOMContentLoaded, reads the inline `<script type="application/
 * json" data-ui-grid-bundle>` for the column model + refresh-marker
 * name + page-fallback URL, and progressively enhances the
 * server-rendered fallback table:
 *
 *   - filter form (caller-owned, marked with `[data-ui-grid-form]`)
 *     submit → preventDefault → fetch the JSON data URL with
 *     current state;
 *   - Next-link (`[data-ui-grid-next]`) click → preventDefault →
 *     fetch with cursor;
 *   - Canonical SSE data delivery (Phase 6): listen for the
 *     `semitexa:ui-sse:component-state` document event dispatched
 *     by event-runtime.js when a canonical `ui.componentState`
 *     frame arrives on the page's KISS stream. Match the frame's
 *     `componentInstanceId` against this grid's instance id; when the
 *     frame carries a row bag (`state.initialRows`), render it directly
 *     — gated by the dispatch `correlationId` so a stale in-flight
 *     dispatch never wins. A bare `state.refresh === true` frame still
 *     triggers a data-URL reload (server-push refresh signal).
 *     event-runtime owns the EventSource — the grid never opens its own
 *     connection. The HTTP dispatch response is a bare ack; row data
 *     never travels over it.
 *
 * Safety boundaries:
 *
 *   - DOM mutations use `createElement` + `textContent` only.
 *   - Cell attributes come from `setAttribute` with a small literal
 *     allow-list (`style`, `ui-text`, `data-ui-grid-row-id`); the
 *     column `style` string is sourced from the SERVER-rendered
 *     bundle, NOT from the JSON envelope.
 *   - Row keys are filtered through the bundle's `columns` allow-
 *     list. Extra keys in server-supplied rows are ignored.
 *   - The JSON envelope is shape-checked before any DOM update;
 *     any deviation surfaces an error banner.
 *   - No innerHTML, no eval, no Function constructor, no
 *     script-tag rendering, no arbitrary selectors from server
 *     payload, no client-side dataset cache.
 *
 * Namespace:
 *
 *   window.SemitexaUi.grid.bootAll()  — explicit re-discovery hook
 *                                       (auto-runs on DOMContentLoaded).
 */
(function () {
    'use strict';

    if (!window.SemitexaUi) window.SemitexaUi = {};

    var DEFAULT_REFRESH_MARKER = 'grid-refresh-marker';

    // Pagination-window defaults + clamp bounds. The runtime reads
    // the grid root's `data-ui-grid-pagination-window-size`, falls
    // back to DEFAULT_PAGE_WINDOW when unset / non-numeric / out of
    // range, and clamps the rest to [MIN, MAX]. The Twig template
    // also clamps server-side as defence in depth.
    var DEFAULT_PAGE_WINDOW = 7;
    var MIN_PAGE_WINDOW     = 1;
    var MAX_PAGE_WINDOW     = 25;

    /**
     * Pure sliding-window helper for the pagination footer.
     *
     * Given the current page, the number of visited (known) pages,
     * and the configured window size, returns the inclusive
     * [start, end] page range that the runtime should render as
     * visited-page buttons. The window:
     *
     *   - never extends past `knownPages` (cursor pagination has no
     *     total-count or page-N surface — fabricating future page
     *     buttons would offer cursors we don't have);
     *   - never starts before 1;
     *   - prefers centering `currentPage`, shifting toward the start
     *     when near 1 and toward the end when near `knownPages`;
     *   - returns `{start: 0, end: 0}` when `knownPages` is zero,
     *     so the caller can render an empty footer.
     *
     * Exposed on `window.SemitexaUi.grid` so the static-assert pin
     * test (and any future Node-side test harness) can exercise it.
     */
    function computePageWindow(currentPage, knownPages, windowSize) {
        if (!isFinite(knownPages) || knownPages <= 0) {
            return { start: 0, end: 0 };
        }
        if (!isFinite(windowSize) || windowSize < MIN_PAGE_WINDOW) {
            windowSize = DEFAULT_PAGE_WINDOW;
        }
        if (windowSize > MAX_PAGE_WINDOW) windowSize = MAX_PAGE_WINDOW;
        if (windowSize > knownPages)     windowSize = knownPages;
        if (currentPage < 1)             currentPage = 1;
        if (currentPage > knownPages)    currentPage = knownPages;

        var half  = Math.floor((windowSize - 1) / 2);
        var start = currentPage - half;
        var end   = start + windowSize - 1;
        if (start < 1) {
            end  += (1 - start);
            start = 1;
        }
        if (end > knownPages) {
            start -= (end - knownPages);
            end    = knownPages;
        }
        if (start < 1) start = 1;
        return { start: start, end: end };
    }

    function bootGrid(rootEl) {
        if (!rootEl || rootEl.__uiGridBooted) return;
        rootEl.__uiGridBooted = true;

        var dataUrl = rootEl.getAttribute('data-ui-grid-data-url');
        if (typeof dataUrl !== 'string' || dataUrl === '') return;

        var instanceId           = rootEl.getAttribute('data-ui-component-instance-id') || '';
        var subscriberChannelId  = rootEl.getAttribute('data-ui-grid-subscriber-channel-id') || '';
        var refreshMarker        = rootEl.getAttribute('data-ui-grid-refresh-marker') || DEFAULT_REFRESH_MARKER;
        // Pagination-window size: read from the server-rendered data
        // attribute, fall back to the default + clamp out-of-range /
        // non-numeric values. Defence in depth — the Twig template
        // already clamps server-side, but a runtime DOM tamper would
        // re-enter the same code path.
        var windowSizeRaw = parseInt(rootEl.getAttribute('data-ui-grid-pagination-window-size') || '', 10);
        var paginationWindowSize =
            (isFinite(windowSizeRaw) && windowSizeRaw >= MIN_PAGE_WINDOW && windowSizeRaw <= MAX_PAGE_WINDOW)
                ? windowSizeRaw
                : DEFAULT_PAGE_WINDOW;

        var bundle = readBundle(rootEl);
        var columns = Array.isArray(bundle && bundle.columns) ? bundle.columns : [];
        var pageFallbackUrl = bundle && typeof bundle.pageFallbackUrl === 'string'
            ? bundle.pageFallbackUrl
            : '';
        var sortParam = bundle && typeof bundle.sortParam === 'string' && bundle.sortParam !== ''
            ? bundle.sortParam
            : 'sort';
        var initialSort = bundle && typeof bundle.initialSort === 'string'
            ? bundle.initialSort
            : '';

        var formEl     = rootEl.querySelector('[data-ui-grid-form]');
        var tbodyEl    = rootEl.querySelector('[data-ui-grid-tbody]');
        var nextLinkEl = rootEl.querySelector('[data-ui-grid-next]');
        var errorEl    = rootEl.querySelector('[data-ui-grid-error]');
        var liveEl     = rootEl.querySelector('[data-ui-grid-live-indicator]');

        var state = {
            q:      readFormValue(formEl, 'q'),
            action: readFormValue(formEl, 'action'),
            limit:  readFormValue(formEl, 'limit') || '25',
            sort:   readFormValue(formEl, sortParam) || initialSort || '',
            cursor: null,
            // Cursor history stack: cursors[K-1] is the cursor that
            // was used to fetch page K. Index 0 is always null
            // (page 1 has no inbound cursor). The runtime pushes
            // the response's nextCursor onto the stack on Next, and
            // pops back to a prior entry on Previous / numbered
            // page click. Reset (back to [null]) whenever criteria
            // changes — the server-side cursor fingerprint guard
            // would reject a stale cross-criteria cursor anyway,
            // but we clear early so the UI never offers a
            // misleading "Page 3" button after the user changed
            // the sort.
            cursors: [null],
            page:    1,
            // Resolved pagination strategy for the CURRENT view, set
            // from each response's `pagination.mode`. 'cursor' keeps the
            // keyset/visited-pages behaviour; 'count'/'offset' switch the
            // footer to a windowed page-number strip with arbitrary
            // page-N jumps (the server reports an exact `totalCount`).
            mode:       'cursor',
            totalCount: null,
            totalPages: null,
        };
        var latestRequestId = 0;

        // True when the active view pages by offset (windowed page
        // numbers) rather than by opaque keyset cursor.
        function isOffsetMode() {
            return state.mode === 'count' || state.mode === 'offset';
        }

        // --- Canonical dispatch correlation tracking ---------------------
        // Phase 6 moved the grid's row data OFF the HTTP response and onto
        // the SSE `ui.componentState` frame. POST /__ui/event now returns
        // only a bare ack (no `debug.state`); the data arrives over the
        // page-session KISS channel and is rendered by the
        // `semitexa:ui-sse:component-state` listener below.
        //
        // event-runtime emits `semitexa:ui-event:dispatching` synchronously
        // before each POST, carrying the per-attempt `correlationId`. We
        // record the latest correlationId for THIS grid instance so the SSE
        // listener can reject a stale in-flight dispatch's frame: if the
        // user clicks Next twice, both dispatches publish to the shared
        // channel and only the frame matching the most recent dispatch's
        // correlationId should win.
        var latestCorrelationId = null;
        document.addEventListener('semitexa:ui-event:dispatching', function (ev) {
            var detail = ev && ev.detail;
            if (!detail || !detail.captured) return;
            if (detail.captured.instanceId !== instanceId) return;
            if (typeof detail.correlationId === 'string' && detail.correlationId !== '') {
                latestCorrelationId = detail.correlationId;
            }
        });

        // --- Lost-frame watchdog -----------------------------------------
        // A live-mode gesture dispatch returns only a bare ack; the row
        // data arrives later over the SSE `ui.componentState` frame. If
        // that frame is lost (e.g. the shared stream dropped during a
        // reconnect), nothing would re-render and the grid would sit stale
        // forever. After a successful dispatch we arm a short watchdog
        // keyed to the dispatch's correlationId; the component-state
        // listener disarms it on a matching render, otherwise we fall back
        // to the deterministic HTTP fetch path. dataUrl is always present
        // here (bootGrid bails when it is empty), so the fallback is safe.
        var FRAME_WATCHDOG_MS = 5000;
        var frameWatchdogTimer = null;

        function clearFrameWatchdog() {
            if (frameWatchdogTimer !== null) {
                clearTimeout(frameWatchdogTimer);
                frameWatchdogTimer = null;
            }
        }

        function armFrameWatchdog() {
            clearFrameWatchdog();
            frameWatchdogTimer = setTimeout(function () {
                frameWatchdogTimer = null;
                if (typeof console !== 'undefined' && console.warn) {
                    console.warn('[semitexa-ui] grid: SSE frame timeout, fell back to HTTP');
                }
                fetchLegacyAndRender();
            }, FRAME_WATCHDOG_MS);
        }

        // --- Filter form -------------------------------------------------
        if (formEl) {
            formEl.addEventListener('submit', function (event) {
                event.preventDefault();
                applyFormStateAndReload();
            });

            // Opt-in auto-submit on change for controls (typically the
            // page-size <select>) that should re-fetch the grid the
            // moment their value changes, without waiting for the
            // explicit Filter submit. The marker is read off the
            // CHANGED element, not the form: only elements that carry
            // `data-ui-grid-reload-on-change` participate, so free-
            // text inputs (q) and other controls keep their current
            // submit-driven behavior.
            formEl.addEventListener('change', function (event) {
                var target = event.target;
                if (!target || typeof target.getAttribute !== 'function') return;
                if (target.getAttribute('data-ui-grid-reload-on-change') === null) return;
                applyFormStateAndReload();
            });
        }

        // Shared state-read + cursor-reset + reload path used by both
        // the submit listener and the auto-submit-on-change listener.
        // Keeping the two entry points routed through one function
        // means a future field added to `state` only has to be wired
        // here once. Criteria changes ALWAYS reset pagination back to
        // page 1 and clear the cursor history — a cursor minted under
        // the old criteria would either fail the server's fingerprint
        // guard (sort/q/action changes) or land mid-stream (limit
        // change), and either way "Previous" no longer means what
        // the user expected.
        function applyFormStateAndReload() {
            state.q      = readFormValue(formEl, 'q');
            state.action = readFormValue(formEl, 'action');
            state.limit  = readFormValue(formEl, 'limit') || '25';
            // Sort comes from a caller-owned hidden input named
            // `sortParam`; if absent, keep the prior state.sort
            // so a filter-only re-fetch does not silently reset it.
            var submittedSort = readFormValue(formEl, sortParam);
            if (submittedSort !== '') {
                state.sort = submittedSort;
            }
            resetPaginationHistory();
            reload({ part: 'filters', event: 'submit', value: currentCriteriaPayload() });
        }

        function currentCriteriaPayload() {
            var p = {};
            if (state.q !== null && state.q !== undefined && state.q !== '')                p.q      = state.q;
            if (state.action !== null && state.action !== undefined && state.action !== '') p.action = state.action;
            if (state.limit !== null && state.limit !== undefined && state.limit !== '')    p.limit  = state.limit;
            if (state.sort !== null && state.sort !== undefined && state.sort !== '')       p.sort   = state.sort;
            if (isOffsetMode()) {
                // Windowed mode pages by 1-indexed number, never a
                // cursor. The server clamps out-of-range pages.
                if (state.page > 1) p.page = String(state.page);
            } else if (state.cursor !== null && state.cursor !== undefined && state.cursor !== '') {
                p.cursor = state.cursor;
            }
            return p;
        }

        function resetPaginationHistory() {
            state.cursor  = null;
            state.cursors = [null];
            state.page    = 1;
        }

        // --- Sortable column header click --------------------------------
        // A header anchor `[data-ui-grid-sort]` carries the column's
        // two allow-listed tokens via `data-ui-grid-sort-asc` and
        // `data-ui-grid-sort-desc`. Click flips between them based on
        // the currently-active sort token; first click on an inactive
        // column defaults to the descending token (the conventional
        // default for date-like fields). The bad-sort branch is
        // unreachable from this UI because the tokens originate
        // server-side; the data endpoint still re-validates them.
        rootEl.addEventListener('click', function (event) {
            var target = event.target;
            // Walk up to the anchor — clicks may land on the inner
            // <span> label or the indicator glyph.
            while (target && target !== rootEl && (!target.getAttribute || target.getAttribute('data-ui-grid-sort') === null)) {
                target = target.parentNode;
            }
            if (!target || target === rootEl) return;
            var asc  = target.getAttribute('data-ui-grid-sort-asc')  || '';
            var desc = target.getAttribute('data-ui-grid-sort-desc') || '';
            if (asc === '' || desc === '') return;
            event.preventDefault();
            var next;
            if (state.sort === asc) {
                next = desc;
            } else if (state.sort === desc) {
                next = asc;
            } else {
                next = desc;
            }
            state.sort = next;
            // A sort change invalidates every cached cursor (the
            // server-side fingerprint guard rejects cross-sort
            // reuse anyway). Reset the client-side history so the
            // UI doesn't offer Previous / numbered buttons that
            // point at the old sort's pages.
            resetPaginationHistory();
            // Update aria-sort + indicator glyph immediately so the
            // UI reflects the new sort before the data round-trip
            // returns.
            updateSortableHeaders(state.sort);
            // Mirror the new sort into the caller-owned hidden input
            // (if present) so a subsequent filter-only submit picks
            // it up — Option B's contract: sort travels as a hidden
            // form input, never as part of UiGridFilterState.
            if (formEl) {
                var sortInput = formEl.elements && formEl.elements.namedItem(sortParam);
                if (sortInput && typeof sortInput.value === 'string') {
                    sortInput.value = state.sort;
                }
            }
            reload({ part: 'rows', event: 'sort', value: currentCriteriaPayload() });
        });

        // --- Next-page link ----------------------------------------------
        if (nextLinkEl) {
            nextLinkEl.addEventListener('click', function (event) {
                if (isOffsetMode()) {
                    // Windowed mode advances by page number — no cursor.
                    event.preventDefault();
                    navigateForward(null);
                    return;
                }
                var cursor = nextLinkEl.getAttribute('data-ui-grid-next-cursor');
                if (typeof cursor !== 'string' || cursor === '') {
                    // No JS-set cursor yet → fall back to the
                    // server-rendered href (no-JS path).
                    return;
                }
                event.preventDefault();
                navigateForward(cursor);
            });
        }

        // --- Previous-page button + visited-page buttons -----------------
        // Both live inside the pagination nav. We delegate from the
        // root rather than rebinding on every render so the listeners
        // never leak.
        rootEl.addEventListener('click', function (event) {
            var target = event.target;
            if (!target || typeof target.closest !== 'function') return;
            var prevEl = target.closest('[data-ui-grid-prev]');
            if (prevEl) {
                event.preventDefault();
                navigateBackward();
                return;
            }
            var pageEl = target.closest('[data-ui-grid-page]');
            if (pageEl) {
                event.preventDefault();
                var pageRaw = pageEl.getAttribute('data-ui-grid-page');
                var pageNum = parseInt(pageRaw || '', 10);
                if (!isFinite(pageNum) || pageNum < 1) return;
                navigateToPage(pageNum);
            }
        });

        function navigateForward(nextCursor) {
            if (isOffsetMode()) {
                // Windowed mode: advance by page number, no cursor.
                if (state.totalPages !== null && state.page >= state.totalPages) return;
                state.page += 1;
                reload({ part: 'rows', event: 'paginate', value: currentCriteriaPayload() });
                return;
            }
            // Push the new cursor onto the history stack only if we
            // haven't already visited this page. If the user clicks
            // Next while there's already a known forward entry (e.g.
            // they came back via Previous), we re-walk the existing
            // entry rather than appending a stale duplicate.
            var nextPage = state.page + 1;
            if (state.cursors.length < nextPage) {
                state.cursors.push(nextCursor);
            } else {
                state.cursors[nextPage - 1] = nextCursor;
            }
            state.page   = nextPage;
            state.cursor = nextCursor;
            reload({ part: 'rows', event: 'paginate', value: currentCriteriaPayload() });
        }

        function navigateBackward() {
            if (state.page <= 1) return;
            state.page--;
            if (!isOffsetMode()) {
                state.cursor = state.cursors[state.page - 1] || null;
            }
            reload({ part: 'rows', event: 'paginate', value: currentCriteriaPayload() });
        }

        function navigateToPage(targetPage) {
            if (isOffsetMode()) {
                // Windowed mode: any page in [1, totalPages] is a valid
                // jump — the offset read goes straight there. The server
                // re-clamps defensively.
                var max = state.totalPages !== null ? state.totalPages : targetPage;
                if (targetPage < 1 || targetPage > max) return;
                state.page = targetPage;
                reload({ part: 'rows', event: 'paginate', value: currentCriteriaPayload() });
                return;
            }
            // Cursor mode: visited-page buttons are only rendered for
            // pages whose cursor we've actually seen, so a click should
            // always resolve. We still bound-check defensively.
            if (targetPage < 1 || targetPage > state.cursors.length) return;
            state.page   = targetPage;
            state.cursor = state.cursors[targetPage - 1] || null;
            reload({ part: 'rows', event: 'paginate', value: currentCriteriaPayload() });
        }

        // --- Canonical refresh signal ------------------------------------
        // The grid does NOT open its own EventSource. When the page
        // opted into canonical KISS via
        // `ui_page_sse_session_meta('live')`, event-runtime.js opens
        // a single shared stream and republishes every
        // `ui.componentState` frame as a `semitexa:ui-sse:component-
        // state` document event. We subscribe here, filter by
        // `componentInstanceId` so frames addressed to other grids
        // (or other components) are ignored, and reload via fetch
        // when the frame's `state.refresh === true`. Instance ids
        // are global random — no `data-ui-component` cross-check is
        // necessary; a collision would require predicting 128 bits
        // of entropy.
        if (subscriberChannelId !== '' && instanceId !== '') {
            if (liveEl) liveEl.hidden = false;
            document.addEventListener('semitexa:ui-sse:component-state', function (event) {
                var detail = event && event.detail;
                if (!detail || !detail.message) return;
                var msg = detail.message;
                if (msg.componentInstanceId !== instanceId) return;
                var sseState = msg.state;
                if (!sseState || typeof sseState !== 'object') return;

                // Phase 6 data delivery: the frame carries this grid's
                // resolved row state (the data provider's prop bag). Render
                // it directly — this is the real SSE data channel, not a
                // refresh ping. Gate on correlationId so a stale earlier
                // dispatch's frame can never overwrite a newer request's
                // result: the page-session channel is shared across this
                // grid's dispatches, correlationId multiplexes it. A frame
                // with no correlationId is a server-pushed snapshot (no
                // client dispatch to correlate against) and is accepted.
                if (Array.isArray(sseState.initialRows)) {
                    if (msg.correlationId && latestCorrelationId &&
                        msg.correlationId !== latestCorrelationId) {
                        return;
                    }
                    // The awaited frame arrived — disarm the watchdog so it
                    // does not redundantly re-pull over HTTP.
                    clearFrameWatchdog();
                    renderPage({
                        rows: sseState.initialRows,
                        pagination: sseState.initialPagination || {},
                    });
                    return;
                }

                // Legacy refresh signal: a bare `{ refresh: true }` snapshot
                // tells the grid to re-pull via its data URL. Used by
                // server-side pushes that do not carry a full row bag.
                if (sseState.refresh === true) {
                    reload();
                }
            });

            // Self-heal: the shared stream re-established after a drop. Any
            // `ui.componentState` frame published while the socket was down
            // may have been lost, so re-pull the current view over HTTP.
            // reload() with no gesture routes to fetchLegacyAndRender(),
            // which composes the URL from the grid's current `state`.
            document.addEventListener('semitexa:ui-sse:reconnected', function () {
                reload();
            });
        }

        // --- Sortable header state sync -----------------------------------
        function updateSortableHeaders(activeSort) {
            var headers = rootEl.querySelectorAll('[data-ui-grid-sort]');
            for (var i = 0; i < headers.length; i++) {
                var headerEl = headers[i];
                var asc  = headerEl.getAttribute('data-ui-grid-sort-asc')  || '';
                var desc = headerEl.getAttribute('data-ui-grid-sort-desc') || '';
                var ariaState = 'none';
                var glyph     = '↕';
                var nextToken = desc;
                if (asc !== '' && activeSort === asc) {
                    ariaState = 'ascending';
                    glyph     = '▲';
                    nextToken = desc;
                } else if (desc !== '' && activeSort === desc) {
                    ariaState = 'descending';
                    glyph     = '▼';
                    nextToken = asc;
                }
                var th = headerEl.parentNode;
                if (th && th.setAttribute) {
                    th.setAttribute('aria-sort', ariaState);
                }
                var indicator = headerEl.querySelector('[data-ui-grid-sort-indicator]');
                if (indicator) indicator.textContent = glyph;
                if (pageFallbackUrl !== '') {
                    headerEl.setAttribute('href', composePageUrl(pageFallbackUrl, {
                        q:      state.q,
                        action: state.action,
                        limit:  state.limit,
                        sort:   nextToken,
                        cursor: '',
                    }, sortParam));
                }
            }
        }

        // --- Core reload: dispatch via /__ui/event, fall back to fetch ----
        //
        // Phase 5/6: when the grid was rendered with a signed UI-event
        // manifest, every filter/sort/paginate gesture dispatches through
        // `window.SemitexaUi.dispatch()`. POST /__ui/event returns only a
        // bare ack; the row data arrives later as a `ui.componentState`
        // SSE frame, rendered by the listener above. A short watchdog
        // (armFrameWatchdog) falls back to the legacy `fetch(dataUrl)` path
        // when that frame never arrives (e.g. the stream dropped during a
        // reconnect). The legacy `fetch(dataUrl)` path is also used for:
        //
        //   - grids whose host page never emits a manifest (e.g.
        //     demo-submissions);
        //   - `reload()` calls with no `gesture` (the SSE
        //     componentState.refresh=true signal and the
        //     semitexa:ui-sse:reconnected self-heal — just refresh,
        //     no manifest gesture);
        //   - any dispatch that returns false (unknown manifest
        //     entry, missing event-runtime).
        function reload(gesture) {
            if (gesture && typeof gesture === 'object' && gesture.part && gesture.event &&
                window.SemitexaUi && typeof window.SemitexaUi.dispatch === 'function' &&
                instanceId !== '') {
                clearError();
                var sent = window.SemitexaUi.dispatch({
                    instanceId: instanceId,
                    part:       gesture.part,
                    event:      gesture.event,
                    value:      gesture.value
                });
                if (sent) {
                    // POST /__ui/event returns only a bare ack; the row
                    // data arrives later as a `ui.componentState` SSE frame
                    // handled by the listener above. Arm a watchdog so a
                    // lost frame falls back to the HTTP path instead of
                    // leaving the grid stale.
                    armFrameWatchdog();
                    return;
                }
                // Dispatch found no matching manifest entry — fall through.
            }
            fetchLegacyAndRender();
        }

        function fetchLegacyAndRender() {
            var requestId = ++latestRequestId;
            clearError();
            var url = composeUrl(dataUrl, state, sortParam);
            fetch(url, {
                method:      'GET',
                credentials: 'same-origin',
                headers:     { 'Accept': 'application/json' },
            }).then(function (resp) {
                return resp.json().then(function (body) {
                    return { status: resp.status, body: body };
                }).catch(function () {
                    return { status: resp.status, body: null };
                });
            }).then(function (parsed) {
                if (requestId !== latestRequestId) return;
                if (!parsed.body || typeof parsed.body !== 'object') {
                    renderError('The server returned an unexpected response.');
                    return;
                }
                if (parsed.body.ok === false) {
                    renderError(safeString(parsed.body.message) || 'Request failed.');
                    return;
                }
                renderPage(parsed.body);
            }).catch(function () {
                if (requestId !== latestRequestId) return;
                renderError('Network error — please retry.');
            });
        }

        function renderPage(envelope) {
            if (!Array.isArray(envelope.rows) ||
                !envelope.pagination ||
                typeof envelope.pagination !== 'object') {
                renderError('The server returned an unexpected response shape.');
                return;
            }

            if (tbodyEl) {
                while (tbodyEl.firstChild) {
                    tbodyEl.removeChild(tbodyEl.firstChild);
                }
                for (var i = 0; i < envelope.rows.length; i++) {
                    var trEl = buildRow(envelope.rows[i]);
                    if (trEl) tbodyEl.appendChild(trEl);
                }
            }

            // Empty-state vs table-wrap visibility.
            var emptyEl   = rootEl.querySelector('[data-ui-grid-empty]');
            var tableWrap = rootEl.querySelector('[data-ui-grid-table-wrap]');
            if (envelope.rows.length === 0) {
                if (emptyEl)   emptyEl.hidden   = false;
                if (tableWrap) tableWrap.hidden = true;
            } else {
                if (emptyEl)   emptyEl.hidden   = true;
                if (tableWrap) tableWrap.hidden = false;
            }

            // Pagination text + Next-link state.
            var pagination = envelope.pagination;
            if (typeof pagination.limit === 'number' && pagination.limit > 0) {
                state.limit = String(pagination.limit);
            }
            // Sync the resolved strategy from the response. A server-side
            // `auto` resolution may flip mode between requests (e.g. once
            // a filter shrinks the result set under the threshold), so we
            // trust the response, not a cached assumption.
            if (typeof pagination.mode === 'string' && pagination.mode !== '') {
                state.mode = pagination.mode;
            }
            if (typeof pagination.totalCount === 'number' && pagination.totalCount >= 0) {
                state.totalCount = pagination.totalCount;
                var perPage = (typeof pagination.limit === 'number' && pagination.limit > 0)
                    ? pagination.limit
                    : (parseInt(state.limit, 10) || 1);
                state.totalPages = Math.max(1, Math.ceil(pagination.totalCount / perPage));
            } else {
                state.totalCount = null;
                state.totalPages = null;
            }
            // Offset mode: trust the server's clamped currentPage (it may
            // have clamped an out-of-range jump to the last page). Cursor
            // mode keeps its client-tracked page (the cursor envelope's
            // currentPage is always 1 and must not reset our position).
            if (isOffsetMode() && typeof pagination.currentPage === 'number' && pagination.currentPage >= 1) {
                state.page = pagination.currentPage;
            }
            var sizeEl  = rootEl.querySelector('[data-ui-grid-pagination-size]');
            var countEl = rootEl.querySelector('[data-ui-grid-pagination-count]');
            var labelEl = rootEl.querySelector('[data-ui-grid-pagination-label]');
            var moreEl  = rootEl.querySelector('[data-ui-grid-pagination-more]');
            var lastEl  = rootEl.querySelector('[data-ui-grid-pagination-last]');
            if (sizeEl)  sizeEl.textContent  = String(pagination.limit || '');
            if (countEl) countEl.textContent = String(envelope.rows.length);
            if (labelEl) labelEl.textContent = envelope.rows.length === 1 ? 'row' : 'rows';
            if (moreEl)  moreEl.hidden = !pagination.hasMore;
            if (lastEl)  lastEl.hidden = !!pagination.hasMore;

            var nextWrap = rootEl.querySelector('[data-ui-grid-next-wrap]');
            if (isOffsetMode()) {
                // Windowed mode: a "next" affordance is shown while more
                // pages remain, but it carries no cursor — navigation is
                // by page number (the click handler routes to
                // navigateForward()).
                if (nextWrap) nextWrap.hidden = !pagination.hasMore;
                if (nextLinkEl) {
                    nextLinkEl.removeAttribute('data-ui-grid-next-cursor');
                    if (pageFallbackUrl !== '') {
                        nextLinkEl.setAttribute('href', composePageUrl(pageFallbackUrl, {
                            q:      state.q,
                            action: state.action,
                            limit:  state.limit,
                            sort:   state.sort,
                            page:   String(state.page + 1),
                        }, sortParam));
                    }
                }
            } else if (pagination.hasMore &&
                typeof pagination.nextCursor === 'string' && pagination.nextCursor !== '') {
                if (nextWrap) nextWrap.hidden = false;
                if (nextLinkEl) {
                    nextLinkEl.setAttribute('data-ui-grid-next-cursor', pagination.nextCursor);
                    if (pageFallbackUrl !== '') {
                        nextLinkEl.setAttribute('href', composePageUrl(pageFallbackUrl, {
                            q:      state.q,
                            action: state.action,
                            limit:  state.limit,
                            sort:   state.sort,
                            cursor: pagination.nextCursor,
                        }, sortParam));
                    }
                }
            } else {
                if (nextWrap) nextWrap.hidden = true;
                if (nextLinkEl) nextLinkEl.removeAttribute('data-ui-grid-next-cursor');
            }

            renderPagination(pagination);

            // Filter-state strip — runtime updates the visible q /
            // action text but never the surrounding markup (the
            // strip itself is caller-owned via the filterState slot).
            var filters = envelope.filters || {};
            var filterStateEl = rootEl.querySelector('[data-ui-grid-filter-state]');
            var filterQ       = rootEl.querySelector('[data-ui-grid-filter-state-q]');
            var filterAction  = rootEl.querySelector('[data-ui-grid-filter-state-action]');
            var hasFilter = (typeof filters.q === 'string' && filters.q !== '')
                         || (typeof filters.action === 'string' && filters.action !== '');
            if (filterStateEl) filterStateEl.hidden = !hasFilter;
            if (filterQ)       filterQ.textContent  = typeof filters.q === 'string' ? filters.q : '';
            if (filterAction)  filterAction.textContent = typeof filters.action === 'string' ? filters.action : '';
        }

        // Pagination footer — Previous button, visited-page numbered
        // buttons, current-page indicator. Built with createElement +
        // textContent only (safe-DOM contract). Numbered buttons are
        // visited-page-only: we render them for pages whose cursor we
        // have actually seen (page 1 always counts because its cursor
        // is the implicit `null`); we do NOT fabricate buttons for
        // unknown future pages because the cursor model has no total-
        // count or page-N support.
        function renderPagination(pagination) {
            if (isOffsetMode()) {
                renderWindowedPagination();
                return;
            }
            renderCursorPagination(pagination);
        }

        // One page-number button. Shared by both footers so the safe-DOM
        // (createElement + textContent) construction lives in one place.
        function buildPageButton(p, currentPage) {
            var btn = document.createElement('button');
            btn.setAttribute('type', 'button');
            btn.setAttribute('data-ui-grid-page', String(p));
            if (p === currentPage) {
                btn.setAttribute('aria-current', 'page');
                btn.setAttribute('style',
                    'padding:0.25rem 0.5rem;border:1px solid var(--ui-action-primary);background:var(--ui-action-primary);color:var(--ui-action-on-primary);border-radius:var(--ui-radius-sm);font-size:0.8125rem;cursor:default;');
            } else {
                btn.setAttribute('style',
                    'padding:0.25rem 0.5rem;border:1px solid var(--ui-border-subtle);background:var(--ui-surface-raised);color:inherit;border-radius:var(--ui-radius-sm);font-size:0.8125rem;cursor:pointer;');
            }
            btn.textContent = String(p);
            return btn;
        }

        // Cursor mode — visited-page window. Numbered buttons are
        // visited-page-only: we render them for pages whose cursor we
        // have actually seen (page 1 always counts because its cursor
        // is the implicit `null`); we do NOT fabricate buttons for
        // unknown future pages because the cursor model has no total-
        // count or page-N support.
        function renderCursorPagination(pagination) {
            var prevEl = rootEl.querySelector('[data-ui-grid-prev]');
            if (prevEl) {
                prevEl.hidden = !(state.page > 1);
            }

            var indicatorEl = rootEl.querySelector('[data-ui-grid-page-indicator]');
            if (indicatorEl) {
                var label = 'Page ' + state.page;
                if (pagination && pagination.hasMore) {
                    label += ' · more available';
                }
                indicatorEl.textContent = label;
            }

            var pagesContainer = rootEl.querySelector('[data-ui-grid-pages]');
            if (pagesContainer) {
                while (pagesContainer.firstChild) {
                    pagesContainer.removeChild(pagesContainer.firstChild);
                }
                var visited = state.cursors.length;
                var range = computePageWindow(state.page, visited, paginationWindowSize);

                // Leading ellipsis — non-clickable, aria-hidden. Tells
                // the user there are visited pages BEFORE the visible
                // window without implying jump-to-page. Never a
                // button, never a [data-ui-grid-page] node — the
                // click delegator only fires for those, so this can
                // never trigger navigation.
                if (range.start > 1) {
                    pagesContainer.appendChild(buildEllipsisNode());
                }

                for (var p = range.start; p <= range.end; p++) {
                    pagesContainer.appendChild(buildPageButton(p, state.page));
                }

                // Trailing ellipsis — same rules as the leading one.
                if (range.end < visited) {
                    pagesContainer.appendChild(buildEllipsisNode());
                }

                // Pagination container is hidden when no rows + no
                // visited history; otherwise we always reveal it (it
                // lives inside data-ui-grid-table-wrap so it hides
                // alongside the table on the empty branch already).
                pagesContainer.hidden = (visited === 0);
            }
        }

        // Count/offset mode — full windowed page-number strip. Because
        // the server reports an exact totalCount we know the real page
        // count up front: render a centered sliding window plus first/
        // last anchors and ellipses, and let every button jump straight
        // to its page. This is what makes [1,2,3,4,5] appear on first
        // load and recenter (e.g. [2,3,4,5,6]) when the user clicks 3.
        function renderWindowedPagination() {
            var totalPages = state.totalPages !== null ? state.totalPages : 1;
            var current = state.page;

            var prevEl = rootEl.querySelector('[data-ui-grid-prev]');
            if (prevEl) {
                prevEl.hidden = !(current > 1);
            }

            var indicatorEl = rootEl.querySelector('[data-ui-grid-page-indicator]');
            if (indicatorEl) {
                indicatorEl.textContent = 'Page ' + current + ' of ' + totalPages;
            }

            var pagesContainer = rootEl.querySelector('[data-ui-grid-pages]');
            if (!pagesContainer) return;
            while (pagesContainer.firstChild) {
                pagesContainer.removeChild(pagesContainer.firstChild);
            }

            var range = computePageWindow(current, totalPages, paginationWindowSize);

            // Leading first-page anchor + ellipsis when the window does
            // not start at page 1.
            if (range.start > 1) {
                pagesContainer.appendChild(buildPageButton(1, current));
                if (range.start > 2) {
                    pagesContainer.appendChild(buildEllipsisNode());
                }
            }

            for (var p = range.start; p <= range.end; p++) {
                pagesContainer.appendChild(buildPageButton(p, current));
            }

            // Trailing ellipsis + last-page anchor when the window does
            // not reach the final page.
            if (range.end < totalPages) {
                if (range.end < totalPages - 1) {
                    pagesContainer.appendChild(buildEllipsisNode());
                }
                pagesContainer.appendChild(buildPageButton(totalPages, current));
            }

            pagesContainer.hidden = (totalPages <= 0);
        }

        function buildEllipsisNode() {
            var span = document.createElement('span');
            span.setAttribute('aria-hidden', 'true');
            span.setAttribute('style',
                'padding:0.25rem 0.25rem;font-size:0.8125rem;color:var(--ui-text-muted);user-select:none;');
            span.textContent = '…';
            return span;
        }

        // Initial paint — the server rendered the static table fallback
        // plus a cursor-style footer (it cannot windowed-render without
        // running JS). Hydrate the real footer once on boot.
        //
        // Count/offset mode: the boot bundle carries the resolved
        // `initialPagination` (mode + totalCount + currentPage), so we
        // sync state and paint the windowed page-number strip + Next
        // affordance immediately — without this the windowed buttons
        // would not appear until the user's first gesture.
        //
        // Cursor mode (or no bundle pagination): fall back to scraping
        // the DOM (the Next link's data-ui-grid-next-cursor + whether
        // the Next wrap is hidden), preserving the no-JS server fallback.
        (function hydrateInitialPagination() {
            var initialPag = (bundle && bundle.initialPagination && typeof bundle.initialPagination === 'object')
                ? bundle.initialPagination
                : null;

            if (initialPag && typeof initialPag.mode === 'string' && initialPag.mode !== '') {
                state.mode = initialPag.mode;
                if (typeof initialPag.limit === 'number' && initialPag.limit > 0) {
                    state.limit = String(initialPag.limit);
                }
                if (typeof initialPag.totalCount === 'number' && initialPag.totalCount >= 0) {
                    state.totalCount = initialPag.totalCount;
                    var per = (typeof initialPag.limit === 'number' && initialPag.limit > 0)
                        ? initialPag.limit
                        : (parseInt(state.limit, 10) || 1);
                    state.totalPages = Math.max(1, Math.ceil(initialPag.totalCount / per));
                }
                if (isOffsetMode()) {
                    if (typeof initialPag.currentPage === 'number' && initialPag.currentPage >= 1) {
                        state.page = initialPag.currentPage;
                    }
                    // Offset mode pages by number — reveal the Next
                    // affordance (no cursor) while more pages remain.
                    var offNextWrap = rootEl.querySelector('[data-ui-grid-next-wrap]');
                    if (offNextWrap) offNextWrap.hidden = !initialPag.hasMore;
                    if (nextLinkEl) nextLinkEl.removeAttribute('data-ui-grid-next-cursor');
                }
                renderPagination(initialPag);
                return;
            }

            var initialHasMore = false;
            var initialCursor  = '';
            if (nextLinkEl) {
                initialCursor = nextLinkEl.getAttribute('data-ui-grid-next-cursor') || '';
                var initialNextWrap = rootEl.querySelector('[data-ui-grid-next-wrap]');
                if (initialNextWrap && initialNextWrap.hidden !== true && initialCursor !== '') {
                    initialHasMore = true;
                }
            }
            renderPagination({ hasMore: initialHasMore, nextCursor: initialCursor });
        })();

        function buildRow(row) {
            if (!row || typeof row !== 'object') return null;
            var tr = document.createElement('tr');
            tr.setAttribute('style', 'border-top:1px solid var(--ui-border-subtle);');
            if (typeof row.id === 'string') {
                tr.setAttribute('data-ui-grid-row-id', row.id);
            }
            for (var i = 0; i < columns.length; i++) {
                var col = columns[i];
                if (!col || typeof col !== 'object' || typeof col.key !== 'string') continue;
                var td   = document.createElement('td');
                var text = '';
                if (Object.prototype.hasOwnProperty.call(row, col.key) && row[col.key] != null) {
                    text = String(row[col.key]);
                }
                // textContent — the cardinal anti-XSS guarantee.
                td.textContent = text;
                td.setAttribute('ui-text', 'body');
                var baseStyle = 'padding:0.5rem 0.75rem;font-size:0.8125rem;';
                var colStyle  = typeof col.style === 'string' ? col.style : '';
                td.setAttribute('style', baseStyle + colStyle);
                tr.appendChild(td);
            }
            return tr;
        }

        function renderError(message) {
            if (!errorEl) return;
            errorEl.textContent = String(message);
            errorEl.hidden = false;
        }

        function clearError() {
            if (!errorEl) return;
            errorEl.hidden = true;
            errorEl.textContent = '';
        }
    }

    function readBundle(rootEl) {
        var scriptEl = rootEl.querySelector('script[data-ui-grid-bundle]');
        if (!scriptEl) return null;
        try {
            return JSON.parse(scriptEl.textContent || '');
        } catch (_) {
            return null;
        }
    }

    function readFormValue(form, name) {
        if (!form) return '';
        var el = form.elements && form.elements.namedItem(name);
        if (!el) return '';
        return typeof el.value === 'string' ? el.value : '';
    }

    function composeUrl(baseUrl, currentState, sortKey) {
        var params = new URLSearchParams();
        if (currentState.q     && currentState.q !== '')     params.set('q', currentState.q);
        if (currentState.action && currentState.action !== '') params.set('action', currentState.action);
        if (currentState.limit && currentState.limit !== '') params.set('limit', currentState.limit);
        if (currentState.sort && currentState.sort !== '' && sortKey) {
            params.set(sortKey, currentState.sort);
        }
        if (currentState.cursor && currentState.cursor !== '') params.set('cursor', currentState.cursor);
        var qs = params.toString();
        return qs === '' ? baseUrl : baseUrl + '?' + qs;
    }

    function composePageUrl(baseUrl, currentState, sortKey) {
        var params = new URLSearchParams();
        if (currentState.q     && currentState.q !== '')     params.set('q', currentState.q);
        if (currentState.action && currentState.action !== '') params.set('action', currentState.action);
        if (currentState.limit && currentState.limit !== '') params.set('limit', currentState.limit);
        if (currentState.sort && currentState.sort !== '' && sortKey) {
            params.set(sortKey, currentState.sort);
        }
        if (currentState.cursor && currentState.cursor !== '') params.set('cursor', currentState.cursor);
        var qs = params.toString();
        return qs === '' ? baseUrl : baseUrl + '?' + qs;
    }

    function safeString(value) {
        return typeof value === 'string' ? value : '';
    }

    function bootAll(scope) {
        var root = (scope && typeof scope.querySelectorAll === 'function') ? scope : document;
        if (root.matches && root.matches('[data-ui-grid]')) bootGrid(root);
        var roots = root.querySelectorAll('[data-ui-grid]');
        for (var i = 0; i < roots.length; i++) bootGrid(roots[i]);
    }

    window.SemitexaUi.grid = {
        bootAll: bootAll,
        // Expose the pure window helper for browser-console debugging
        // and any future Node-side test harness. No state, no I/O —
        // safe to expose.
        computePageWindow: computePageWindow,
        DEFAULT_PAGE_WINDOW: DEFAULT_PAGE_WINDOW,
        MIN_PAGE_WINDOW:     MIN_PAGE_WINDOW,
        MAX_PAGE_WINDOW:     MAX_PAGE_WINDOW,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { bootAll(); }, { once: true });
    } else {
        bootAll();
    }

    // Late-arriving grids: when a deferred component or template
    // block is delivered via SSE / fetch after DOMContentLoaded, the
    // SSR runtime fires `semitexa:component:rendered` (deferred
    // component) or `semitexa:block:rendered` (deferred slot) with
    // the newly inserted element in `event.detail`. Re-scan that
    // subtree so any `[data-ui-grid]` inside it gets hydrated. The
    // `__uiGridBooted` flag on the root makes re-entry idempotent.
    document.addEventListener('semitexa:component:rendered', function (event) {
        var el = event && event.detail && event.detail.element instanceof Element
            ? event.detail.element
            : document;
        bootAll(el);
    });
    document.addEventListener('semitexa:block:rendered', function (event) {
        var el = event && event.detail && event.detail.block instanceof Element
            ? event.detail.block
            : document;
        bootAll(el);
    });

    // Defence in depth — MutationObserver-driven late-attach. The
    // semitexa:component:rendered listener above covers the documented
    // deferred-SSR path, but some delivery surfaces (e.g. canonical KISS
    // streaming the rendered HTML directly into a deferred placeholder
    // before the runtime listener attaches) drop grid roots into the
    // DOM without firing that event in time. The MutationObserver
    // catches every `[data-ui-grid]` node added under document.body
    // post-DOMContentLoaded and runs bootGrid idempotently — the
    // `__uiGridBooted` flag already guards against double-init.
    if (typeof MutationObserver !== 'undefined' && document.body) {
        var observer = new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                var added = mutations[i].addedNodes;
                if (!added || !added.length) continue;
                for (var j = 0; j < added.length; j++) {
                    var node = added[j];
                    if (!node || node.nodeType !== 1) continue;
                    if (node.matches && node.matches('[data-ui-grid]')) {
                        bootGrid(node);
                    } else if (node.querySelectorAll) {
                        var gridRoots = node.querySelectorAll('[data-ui-grid]');
                        for (var k = 0; k < gridRoots.length; k++) {
                            bootGrid(gridRoots[k]);
                        }
                    }
                }
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }
})();
