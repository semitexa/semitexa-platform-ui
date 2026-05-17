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

    function bootGrid(rootEl) {
        if (!rootEl || rootEl.__uiGridBooted) return;
        rootEl.__uiGridBooted = true;

        var dataUrl = rootEl.getAttribute('data-ui-grid-data-url');
        if (typeof dataUrl !== 'string' || dataUrl === '') return;

        var instanceId    = rootEl.getAttribute('data-ui-component-instance-id') || '';
        var sseUrl        = rootEl.getAttribute('data-ui-grid-sse-url') || '';
        var refreshMarker = rootEl.getAttribute('data-ui-grid-refresh-marker') || DEFAULT_REFRESH_MARKER;

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
        };

        // --- Filter form -------------------------------------------------
        if (formEl) {
            formEl.addEventListener('submit', function (event) {
                event.preventDefault();
                state.q      = readFormValue(formEl, 'q');
                state.action = readFormValue(formEl, 'action');
                state.limit  = readFormValue(formEl, 'limit') || '25';
                // Sort comes from a caller-owned hidden input named
                // `sortParam`; if absent, keep the prior state.sort
                // so a filter-only submit does not silently reset it.
                var submittedSort = readFormValue(formEl, sortParam);
                if (submittedSort !== '') {
                    state.sort = submittedSort;
                }
                state.cursor = null;
                reload();
            });
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
            state.sort   = next;
            state.cursor = null;
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
                state.cursor = cursor;
                reload();
            });
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

    window.SemitexaUi.grid = { bootAll: bootAll };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootAll, { once: true });
    } else {
        bootAll();
    }
})();
