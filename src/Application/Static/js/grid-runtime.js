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
 *   - SSE refresh (`semitexa:ui-sse:patch-applied`) → match
 *     `patch.target.name === <refreshMarker>` AND
 *     `patch.target.instance === <ourInstanceId>` → reload current
 *     state.
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

        var instanceId    = rootEl.getAttribute('data-ui-component-instance-id') || '';
        var sseUrl        = rootEl.getAttribute('data-ui-grid-sse-url') || '';
        var refreshMarker = rootEl.getAttribute('data-ui-grid-refresh-marker') || DEFAULT_REFRESH_MARKER;
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
        };
        var latestRequestId = 0;

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
            reload();
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
            reload();
        });

        // --- Next-page link ----------------------------------------------
        if (nextLinkEl) {
            nextLinkEl.addEventListener('click', function (event) {
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
            reload();
        }

        function navigateBackward() {
            if (state.page <= 1) return;
            state.page--;
            state.cursor = state.cursors[state.page - 1] || null;
            reload();
        }

        function navigateToPage(targetPage) {
            // Visited-page buttons are only rendered for pages whose
            // cursor we've actually seen, so a click on a button
            // should always resolve. We still bound-check defensively.
            if (targetPage < 1 || targetPage > state.cursors.length) return;
            state.page   = targetPage;
            state.cursor = state.cursors[targetPage - 1] || null;
            reload();
        }

        // --- SSE refresh signal ------------------------------------------
        if (sseUrl !== '' &&
            window.SemitexaUi &&
            window.SemitexaUi.sse &&
            typeof window.SemitexaUi.sse.attach === 'function') {
            try {
                window.SemitexaUi.sse.attach({ url: sseUrl });
                if (liveEl) liveEl.hidden = false;
            } catch (_) {
                // SSE setup failed → silent; the grid still works.
            }
            document.addEventListener('semitexa:ui-sse:patch-applied', function (event) {
                var detail = event && event.detail;
                if (!detail || !detail.patch || !detail.patch.target) return;
                var target = detail.patch.target;
                if (target.name !== refreshMarker) return;
                if (target.instance && instanceId && target.instance !== instanceId) return;
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

        // --- Core fetch + render ------------------------------------------
        function reload() {
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
            if (pagination.hasMore &&
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
                    var btn = document.createElement('button');
                    btn.setAttribute('type', 'button');
                    btn.setAttribute('data-ui-grid-page', String(p));
                    var isActive = (p === state.page);
                    if (isActive) {
                        btn.setAttribute('aria-current', 'page');
                        btn.setAttribute('style',
                            'padding:0.25rem 0.5rem;border:1px solid var(--ui-action-primary);background:var(--ui-action-primary);color:var(--ui-action-on-primary);border-radius:var(--ui-radius-sm);font-size:0.8125rem;cursor:default;');
                    } else {
                        btn.setAttribute('style',
                            'padding:0.25rem 0.5rem;border:1px solid var(--ui-border-subtle);background:var(--ui-surface-raised);color:inherit;border-radius:var(--ui-radius-sm);font-size:0.8125rem;cursor:pointer;');
                    }
                    btn.textContent = String(p);
                    pagesContainer.appendChild(btn);
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

        function buildEllipsisNode() {
            var span = document.createElement('span');
            span.setAttribute('aria-hidden', 'true');
            span.setAttribute('style',
                'padding:0.25rem 0.25rem;font-size:0.8125rem;color:var(--ui-text-muted);user-select:none;');
            span.textContent = '…';
            return span;
        }

        // Initial paint — server rendered the static table fallback
        // but cannot know our client-side history shape. Hydrate the
        // pagination footer once on boot using whatever pagination
        // info is encoded in the DOM (the Next link's
        // data-ui-grid-next-cursor + whether the Next wrap is
        // hidden). This keeps the no-JS server fallback intact while
        // making sure Previous / Page 1 / visited-page button 1 are
        // wired before the first click.
        (function hydrateInitialPagination() {
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

    function bootAll() {
        var roots = document.querySelectorAll('[data-ui-grid]');
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
        document.addEventListener('DOMContentLoaded', bootAll, { once: true });
    } else {
        bootAll();
    }
})();
