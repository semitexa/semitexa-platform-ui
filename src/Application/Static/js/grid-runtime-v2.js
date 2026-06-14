/**
 * grid-runtime-v2.js — One Way Phase 3: the metadata-driven grid runtime.
 *
 * Boots every `[data-ui-grid-v2]` shell on the page. The shell carries ONLY
 * an endpoint pointer (`data-ui-grid-endpoint`) plus optional page-local
 * bits (actions, empty message) — there is NO server-projected JSON bundle.
 * The runtime calls `OPTIONS endpoint`, reads the route contract
 * (`input` / `collection` / `output` / optional `ui` blocks) and builds the
 * whole grid from it: columns, sort affordances, filter inputs, search box,
 * per-page selector and pager. Data flows over plain JSON pull
 * (`GET endpoint?q&sort&filter&page|cursor&perPage` →
 * `{data, meta.pagination}`).
 *
 * One Way Phase 4 — SSE transport on the SAME canonical envelope. When the
 * contract advertises `modes: [... 'sse']` and the browser has EventSource,
 * the runtime opens ONE persistent held-open EventSource on the endpoint:
 *   - adopts the server-minted stream id from the first `ui.stream.id`
 *     event (the GET carries NO stream_id; the server is sole coordinate);
 *   - renders every `ui.collection.data` frame through the SAME render()
 *     path as a pull body (the frame IS the canonical `{data, meta}`
 *     envelope, `_type` aside);
 *   - routes view changes as one-URL re-hydrate COMMANDS — a POST to the
 *     same endpoint with `X-Semitexa-Stream-Rehydrate: 1`, the adopted
 *     stream id, the COMPLETE canonical view state and the CSRF token;
 *     fresh rows arrive on the open stream, never on the POST;
 *   - reconnects with exponential backoff on a transport drop (adopting a
 *     fresh id), and degrades PERMANENTLY to plain JSON pull when the
 *     EventSource fails before its first data frame.
 *
 * Hard client rules (One Way design §1.5 / Phase 2 lessons):
 *   - pagination branches on `meta.pagination.mode` ('page' vs 'cursor'),
 *     never on the presence of `nextCursor`;
 *   - page-number affordances are rendered ONLY in 'page' mode — cursor
 *     mode gets Previous (client-side trail) / Next only;
 *   - any change to sort, filter, search or page size drops the cursor and
 *     resets to the first view (cursor tokens are fingerprint-bound);
 *   - the OPTIONS contract is cached in sessionStorage keyed by endpoint
 *     and revalidated with If-None-Match (the server replies 304);
 *   - every non-GET request echoes the `XSRF-TOKEN` cookie back as
 *     `X-CSRF-Token` (CsrfListener gates unsafe methods for authenticated
 *     sessions) — view changes are GETs in JSON mode, so in practice this
 *     covers action invocations and the OPTIONS revalidation is harmless;
 *   - all row/contract values reach the DOM via textContent only.
 */
(function () {
    'use strict';

    window.SemitexaUi = window.SemitexaUi || {};
    if (window.SemitexaUi.gridV2) return;

    var CONTRACT_CACHE_PREFIX = 'semitexa:ui-grid-contract:';
    var DEFAULT_PAGE_WINDOW = 7;

    // ------------------------------------------------------------------
    // CSRF — read the non-HttpOnly XSRF-TOKEN cookie and echo it back as
    // X-CSRF-Token on every non-GET/HEAD request. Sent only when the
    // cookie exists: guests have no cookie and must send nothing.
    // ------------------------------------------------------------------
    function readCsrfToken() {
        var pairs = document.cookie ? document.cookie.split(/;\s*/) : [];
        for (var i = 0; i < pairs.length; i++) {
            var eq = pairs[i].indexOf('=');
            if (eq < 0) continue;
            if (pairs[i].slice(0, eq) === 'XSRF-TOKEN') {
                return decodeURIComponent(pairs[i].slice(eq + 1));
            }
        }
        return '';
    }

    function withCsrf(method, headers) {
        var out = headers || {};
        if (method !== 'GET' && method !== 'HEAD') {
            var token = readCsrfToken();
            if (token) out['X-CSRF-Token'] = token;
        }
        return out;
    }

    // ------------------------------------------------------------------
    // Contract loading — sessionStorage cache keyed by endpoint, ETag
    // revalidation via If-None-Match / 304.
    // ------------------------------------------------------------------
    function readCachedContract(endpoint) {
        try {
            var raw = sessionStorage.getItem(CONTRACT_CACHE_PREFIX + endpoint);
            if (!raw) return null;
            var parsed = JSON.parse(raw);
            if (!parsed || typeof parsed !== 'object' || !parsed.contract) return null;
            return parsed;
        } catch (e) {
            return null;
        }
    }

    function writeCachedContract(endpoint, etag, contract) {
        try {
            sessionStorage.setItem(
                CONTRACT_CACHE_PREFIX + endpoint,
                JSON.stringify({ etag: etag || '', contract: contract })
            );
        } catch (e) { /* quota / private mode — cache is best-effort */ }
    }

    function loadContract(endpoint) {
        var cached = readCachedContract(endpoint);
        var headers = withCsrf('OPTIONS', {});
        if (cached && cached.etag) headers['If-None-Match'] = cached.etag;
        return fetch(endpoint, {
            method: 'OPTIONS',
            headers: headers,
            credentials: 'same-origin',
        }).then(function (res) {
            if (res.status === 304 && cached) return cached.contract;
            if (!res.ok) throw new Error('contract fetch failed (' + res.status + ')');
            return res.json().then(function (contract) {
                writeCachedContract(endpoint, res.headers.get('ETag'), contract);
                return contract;
            });
        });
    }

    // ------------------------------------------------------------------
    // Contract → presentation derivation (One Way design §1.2, option c:
    // smart defaults from `output`, the optional `ui` block overrides).
    // ------------------------------------------------------------------
    function humanizeFieldName(name) {
        var spaced = String(name)
            .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
            .replace(/[_-]+/g, ' ')
            .toLowerCase();
        return spaced.charAt(0).toUpperCase() + spaced.slice(1);
    }

    function deriveColumns(contract) {
        var output = contract.output || {};
        var outputFields = Array.isArray(output.fields) ? output.fields : [];
        var ui = contract.ui || {};
        var idField = typeof output.idField === 'string' ? output.idField : null;

        if (Array.isArray(ui.columns) && ui.columns.length > 0) {
            return ui.columns.map(function (col) {
                return {
                    field: String(col.field || ''),
                    label: typeof col.label === 'string' ? col.label : humanizeFieldName(col.field || ''),
                    format: typeof col.format === 'string' ? col.format : 'text',
                    variants: col.variants && typeof col.variants === 'object' ? col.variants : null,
                    href: typeof col.href === 'string' ? col.href : '',
                };
            });
        }

        return outputFields
            .filter(function (f) { return f && typeof f.name === 'string'; })
            .map(function (f) {
                var format = 'text';
                if (f.name === idField) format = 'mono';
                else if (/(At|_at)$/.test(f.name)) format = 'datetime';
                if (f.kind === 'ref_one' && typeof f.href === 'string' && f.href !== '') {
                    format = 'link';
                }
                return {
                    field: f.name,
                    label: humanizeFieldName(f.name),
                    format: format,
                    variants: null,
                    href: typeof f.href === 'string' ? f.href : '',
                };
            });
    }

    function defaultOperatorFor(operators) {
        if (!Array.isArray(operators) || operators.length === 0) return 'eq';
        if (operators.indexOf('contains') >= 0) return 'contains';
        if (operators.indexOf('eq') >= 0) return 'eq';
        return operators[0];
    }

    // ------------------------------------------------------------------
    // Safe styling primitives — byte-equivalent to the server-side grid
    // shell so v1 and v2 grids look identical while they coexist.
    // ------------------------------------------------------------------
    var TH_STYLE = 'text-align:left;padding:0.5rem 0.75rem;font-size:0.75rem;letter-spacing:0.04em;text-transform:uppercase;';
    var TD_STYLE = 'padding:0.5rem 0.75rem;font-size:0.8125rem;';
    var BUTTON_STYLE = 'padding:0.25rem 0.625rem;border:1px solid var(--ui-border-subtle);background:var(--ui-surface-raised);border-radius:var(--ui-radius-sm);font-size:0.8125rem;cursor:pointer;color:inherit;';
    var INPUT_STYLE = 'padding:0.5rem 0.75rem;border:1px solid var(--ui-border-subtle);border-radius:var(--ui-radius-sm);font-size:0.875rem;';
    var BADGE_BASE = 'display:inline-block;padding:0.125rem 0.5rem;border-radius:999px;font-size:0.75rem;font-weight:600;line-height:1.4;';
    var BADGE_VARIANTS = {
        ok: BADGE_BASE + 'background:var(--ui-state-success-surface,#e6f4ea);color:var(--ui-state-success,#1a7f37);',
        warn: BADGE_BASE + 'background:var(--ui-state-warning-surface,#fff4e5);color:var(--ui-state-warning,#9a6700);',
        mute: BADGE_BASE + 'background:var(--ui-surface-sunken,#eceff1);color:var(--ui-text-muted,#5f6b76);',
    };
    var LINK_CELL_STYLE = 'color:var(--ui-action-primary,#0b66c3);text-decoration:underline;';
    var FORMAT_CELL_STYLES = {
        datetime: 'white-space:nowrap;',
        date: 'white-space:nowrap;',
        mono: 'font-family:var(--ui-font-mono);font-size:0.75rem;',
    };

    function el(tag, attrs, text) {
        var node = document.createElement(tag);
        if (attrs) {
            for (var key in attrs) {
                if (Object.prototype.hasOwnProperty.call(attrs, key) && attrs[key] !== null) {
                    node.setAttribute(key, attrs[key]);
                }
            }
        }
        if (typeof text === 'string') node.textContent = text;
        return node;
    }

    function interpolateHref(template, row) {
        var href = template.replace(/\{([A-Za-z0-9_]+)\}/g, function (_m, field) {
            var value = row && row[field] != null ? String(row[field]) : '';
            return encodeURIComponent(value);
        });
        // Site-relative guard: only root-relative hrefs. Rejects protocol-
        // relative (`//`) AND backslash variants (`/\`) — browsers normalise
        // `\` to `/` in URLs, so `/\evil.com` would become `//evil.com`.
        if (!/^\/(?![\/\\])/.test(href)) return '';
        return href;
    }

    // ------------------------------------------------------------------
    // Grid instance
    // ------------------------------------------------------------------
    function bootGrid(root) {
        if (root.__uiGridV2Booted) return;
        root.__uiGridV2Booted = true;

        var endpoint = root.getAttribute('data-ui-grid-endpoint') || '';
        if (endpoint === '') return;
        // Security: only same-origin, root-relative endpoints. The runtime echoes
        // the XSRF-TOKEN cookie back as X-CSRF-Token on every non-GET request, so
        // an absolute or protocol-relative endpoint would leak that token to a
        // cross-origin host. Refuse anything that is not a single-leading-slash path.
        if (endpoint.charAt(0) !== '/' || endpoint.charAt(1) === '/') {
            root.setAttribute('data-ui-grid-v2-state', 'error');
            return;
        }

        var gridId = root.getAttribute('data-ui-grid-v2') || 'grid';
        var emptyMessage = root.getAttribute('data-ui-grid-empty') || 'No rows.';

        // Page-local action overlay (same role as the no-JS fallback URL:
        // genuinely page-local until the contract serves `ui.actions`).
        var shellActions = [];
        var actionsRaw = root.getAttribute('data-ui-grid-actions');
        if (actionsRaw) {
            try {
                var parsed = JSON.parse(actionsRaw);
                if (Array.isArray(parsed)) shellActions = parsed;
            } catch (e) { /* malformed page-local overlay — ignore */ }
        }

        root.setAttribute('data-ui-grid-v2-state', 'loading');

        loadContract(endpoint).then(function (contract) {
            var instance = createInstance(root, gridId, endpoint, contract, shellActions, emptyMessage);
            instance.start();
        }).catch(function (err) {
            root.setAttribute('data-ui-grid-v2-state', 'error');
            var error = el('p', {
                'data-ui-grid-error': '',
                'ui-text': 'muted',
                style: 'margin:0;border-left:4px solid var(--ui-state-danger);padding:0.75rem;',
                'aria-live': 'polite',
            }, 'Grid unavailable: ' + (err && err.message ? err.message : 'contract error'));
            root.appendChild(error);
        });
    }

    function createInstance(root, gridId, endpoint, contract, shellActions, emptyMessage) {
        var collection = contract.collection || {};
        var paginationPolicy = collection.pagination || {};
        var sortFields = (collection.sort && Array.isArray(collection.sort.fields)) ? collection.sort.fields : [];
        var filterFields = (collection.filter && collection.filter.fields && typeof collection.filter.fields === 'object')
            ? collection.filter.fields : {};
        var search = collection.search || null;
        var ui = contract.ui || {};
        var uiActions = Array.isArray(ui.actions) ? ui.actions : [];
        var actions = uiActions.length > 0 ? uiActions : shellActions;
        var columns = deriveColumns(contract);
        var pageWindow = (ui.client && typeof ui.client.pageWindowSize === 'number')
            ? Math.max(1, Math.min(25, ui.client.pageWindowSize)) : DEFAULT_PAGE_WINDOW;

        var defaultSort = (collection.sort && typeof collection.sort.default === 'string')
            ? collection.sort.default : '';

        var state = {
            q: '',
            sort: defaultSort,
            filters: {},          // field → {op, value}
            perPage: typeof paginationPolicy.defaultPerPage === 'number' ? paginationPolicy.defaultPerPage : null,
            mode: null,           // authoritative ONLY from meta.pagination.mode
            page: 1,
            cursor: '',
            cursorTrail: [''],
            cursorIndex: 0,
            nextCursor: '',
            pulling: false,
            pullAgain: false,     // a refresh landed while a pull was in flight
            recovered: false,     // one-shot guard for invalid_pagination auto-recovery
            // --- One Way Phase 4: SSE transport state -------------------
            transport: null,      // 'sse' | 'pull' — decided in start(), may degrade sse→pull
            streamId: null,       // adopted server-minted id; ONLY set from `ui.stream.id`
            gotFrame: false,      // any data frame on the current stream yet?
            everStreamed: false,  // any frame on ANY connection — gates permanent degrade
            reconnectAttempts: 0,
        };
        var source = null;          // the ONE held-open EventSource
        var reconnectTimer = null;
        var sseAdvertised = Array.isArray(contract.modes) && contract.modes.indexOf('sse') >= 0;
        Object.keys(filterFields).forEach(function (field) {
            state.filters[field] = { op: defaultOperatorFor(filterFields[field]), value: '' };
        });

        // ---- skeleton -------------------------------------------------
        var refs = buildSkeleton();

        function buildSkeleton() {
            var r = {};

            r.error = el('p', {
                'data-ui-grid-error': '',
                hidden: '',
                'ui-text': 'muted',
                'sx-surface': 'panel', 'sx-padding': '3', 'sx-radius': 'md',
                style: 'margin:0;border-left:4px solid var(--ui-state-danger);',
                'aria-live': 'polite',
            });
            root.appendChild(r.error);

            // Controls: search + filters + page size, all derived from the
            // contract; the form exists only when something is declared.
            var hasControls = search !== null
                || Object.keys(state.filters).length > 0
                || (Array.isArray(paginationPolicy.perPageOptions) && paginationPolicy.perPageOptions.length > 0);
            if (hasControls) {
                r.form = el('form', {
                    'data-ui-grid-form': '',
                    method: 'get',
                    'sx-surface': 'panel', 'sx-padding': '3', 'sx-radius': 'md',
                    'sx-layout': 'cluster', 'sx-gap': '2', 'sx-align': 'end',
                    style: 'flex-wrap:wrap;',
                });

                if (search !== null) {
                    var searchLabel = el('label', { 'sx-layout': 'stack', 'sx-gap': '1', style: 'min-width:18rem;flex:1 1 18rem;' });
                    searchLabel.appendChild(el('span', { 'ui-text': 'label', style: 'font-size:0.75rem;letter-spacing:0.04em;text-transform:uppercase;' }, 'Search'));
                    var searchOverlay = (ui.filters && ui.filters.q) || {};
                    r.search = el('input', {
                        type: 'search',
                        name: search.param || 'q',
                        'data-ui-grid-search': '',
                        maxlength: '100',
                        placeholder: typeof searchOverlay.placeholder === 'string' ? searchOverlay.placeholder : '',
                        style: INPUT_STYLE,
                    });
                    searchLabel.appendChild(r.search);
                    r.form.appendChild(searchLabel);
                }

                r.filterInputs = {};
                Object.keys(state.filters).forEach(function (field) {
                    var overlay = (ui.filters && ui.filters[field]) || {};
                    var fLabel = el('label', { 'sx-layout': 'stack', 'sx-gap': '1', style: 'min-width:10rem;' });
                    fLabel.appendChild(el('span', { 'ui-text': 'label', style: 'font-size:0.75rem;letter-spacing:0.04em;text-transform:uppercase;' },
                        typeof overlay.label === 'string' ? overlay.label : humanizeFieldName(field)));
                    var input = el('input', {
                        type: 'text',
                        name: field,
                        'data-ui-grid-filter': field,
                        maxlength: '100',
                        placeholder: typeof overlay.placeholder === 'string' ? overlay.placeholder : '',
                        style: INPUT_STYLE,
                    });
                    fLabel.appendChild(input);
                    r.form.appendChild(fLabel);
                    r.filterInputs[field] = input;
                });

                if (Array.isArray(paginationPolicy.perPageOptions) && paginationPolicy.perPageOptions.length > 0) {
                    var sizeLabel = el('label', { 'sx-layout': 'stack', 'sx-gap': '1', style: 'min-width:7rem;' });
                    sizeLabel.appendChild(el('span', { 'ui-text': 'label', style: 'font-size:0.75rem;letter-spacing:0.04em;text-transform:uppercase;' }, 'Page size'));
                    r.perPage = el('select', { name: 'perPage', 'data-ui-grid-per-page': '', style: INPUT_STYLE });
                    paginationPolicy.perPageOptions.forEach(function (opt) {
                        var option = el('option', { value: String(opt) }, String(opt));
                        if (opt === state.perPage) option.setAttribute('selected', '');
                        r.perPage.appendChild(option);
                    });
                    sizeLabel.appendChild(r.perPage);
                    r.form.appendChild(sizeLabel);
                    r.perPage.addEventListener('change', function () {
                        state.perPage = parseInt(r.perPage.value, 10) || state.perPage;
                        resetView();
                        refresh();
                    });
                }

                if (search !== null || Object.keys(state.filters).length > 0) {
                    r.form.appendChild(el('button', {
                        type: 'submit',
                        style: 'padding:0.5rem 1rem;border:0;border-radius:var(--ui-radius-sm);background:var(--ui-action-primary);color:var(--ui-action-on-primary);font-size:0.875rem;cursor:pointer;',
                    }, 'Apply'));
                    var clear = el('button', { type: 'button', 'data-ui-grid-clear': '', 'ui-text': 'muted', style: 'font-size:0.875rem;border:0;background:none;cursor:pointer;' }, 'Clear');
                    clear.addEventListener('click', function () {
                        if (r.search) r.search.value = '';
                        Object.keys(r.filterInputs || {}).forEach(function (f) { r.filterInputs[f].value = ''; });
                        readControls();
                        resetView();
                        refresh();
                    });
                    r.form.appendChild(clear);
                }

                r.form.addEventListener('submit', function (event) {
                    event.preventDefault();
                    readControls();
                    resetView();
                    refresh();
                });
                root.appendChild(r.form);
            }

            if (actions.length > 0) {
                var toolbar = el('div', { 'data-ui-grid-toolbar': '', 'sx-layout': 'cluster', 'sx-gap': '3', 'sx-align': 'center', style: 'flex-wrap:wrap;' });
                actions.forEach(function (action) {
                    if (!action || typeof action.label !== 'string' || typeof action.route !== 'string') return;
                    var btn = el('button', {
                        type: 'button',
                        'data-ui-grid-action': '',
                        'data-ui-grid-action-route': action.route,
                        'data-ui-grid-action-method': typeof action.method === 'string' ? action.method : 'POST',
                        ui: 'button', 'data-ui-primitive': 'platform.button', 'ui-variant': 'solid', 'ui-tone': 'brand',
                        style: 'margin-left:auto;font-size:0.8125rem;',
                    }, action.label);
                    btn.addEventListener('click', function () { invokeAction(btn); });
                    toolbar.appendChild(btn);
                });
                root.appendChild(toolbar);
            }

            r.empty = el('section', {
                'data-ui-grid-empty': '',
                hidden: '',
                'sx-surface': 'panel', 'sx-padding': '5', 'sx-radius': 'md', 'sx-layout': 'stack', 'sx-gap': '2',
            });
            r.empty.appendChild(el('strong', { 'ui-text': 'label' }, emptyMessage));
            root.appendChild(r.empty);

            r.tableWrap = el('section', { 'data-ui-grid-table-wrap': '', hidden: '', 'sx-layout': 'stack', 'sx-gap': '2' });
            r.paginationText = el('p', { 'data-ui-grid-pagination-text': '', 'ui-text': 'muted', style: 'margin:0;font-size:0.8125rem;' });
            r.tableWrap.appendChild(r.paginationText);

            var panel = el('div', { 'sx-surface': 'panel', 'sx-radius': 'md', style: 'overflow:auto;' });
            var table = el('table', { style: 'width:100%;border-collapse:collapse;' });
            var thead = el('thead', null);
            var headRow = el('tr', { style: 'background:var(--ui-surface-sunken);' });
            r.sortHeaders = {};
            columns.forEach(function (col) {
                var sortable = sortFields.indexOf(col.field) >= 0;
                var th = el('th', { 'ui-text': 'label', style: TH_STYLE });
                if (sortable) {
                    var link = el('a', {
                        'data-ui-grid-sort': '',
                        'data-ui-grid-sort-field': col.field,
                        href: '#',
                        style: 'color:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:0.25rem;',
                    });
                    link.appendChild(el('span', null, col.label));
                    var indicator = el('span', { 'data-ui-grid-sort-indicator': '', 'aria-hidden': 'true', style: 'font-size:0.6875rem;opacity:0.7;' }, '↕');
                    link.appendChild(indicator);
                    link.addEventListener('click', function (event) {
                        event.preventDefault();
                        toggleSort(col.field);
                    });
                    th.setAttribute('aria-sort', 'none');
                    th.appendChild(link);
                    r.sortHeaders[col.field] = { th: th, indicator: indicator };
                } else {
                    th.textContent = col.label;
                }
                headRow.appendChild(th);
            });
            thead.appendChild(headRow);
            table.appendChild(thead);
            r.tbody = el('tbody', { 'data-ui-grid-tbody': '' });
            table.appendChild(r.tbody);
            panel.appendChild(table);
            r.tableWrap.appendChild(panel);

            r.pagination = el('nav', {
                'data-ui-grid-pagination': '',
                'aria-label': 'Grid pagination',
                'sx-layout': 'cluster', 'sx-gap': '2', 'sx-align': 'center',
                style: 'margin:0;flex-wrap:wrap;font-size:0.875rem;',
            });
            r.tableWrap.appendChild(r.pagination);
            root.appendChild(r.tableWrap);

            return r;
        }

        // ---- state helpers --------------------------------------------
        function readControls() {
            if (refs.search) state.q = refs.search.value.trim();
            Object.keys(refs.filterInputs || {}).forEach(function (field) {
                state.filters[field].value = refs.filterInputs[field].value.trim();
            });
        }

        // Any view change (sort / filter / q / perPage) invalidates the
        // cursor fingerprint and the page position — reset both.
        function resetView() {
            state.page = 1;
            state.cursor = '';
            state.cursorTrail = [''];
            state.cursorIndex = 0;
            state.nextCursor = '';
        }

        function toggleSort(field) {
            // First click sorts descending (date-like default, matching the
            // legacy grids), second click flips ascending.
            state.sort = (state.sort === '-' + field) ? field : '-' + field;
            resetView();
            refresh();
        }

        function buildQuery() {
            var params = new URLSearchParams();
            if (state.q !== '') {
                // Use the SERVER-declared search parameter name from the contract
                // (collection.search.param), falling back to 'q'. A grid whose
                // route advertises a custom search key was otherwise sending the
                // wrong query parameter, so search silently did nothing.
                var searchParam = (search && typeof search.param === 'string' && search.param !== '') ? search.param : 'q';
                params.set(searchParam, state.q);
            }
            if (state.sort !== '') params.set('sort', state.sort);
            var terms = [];
            Object.keys(state.filters).forEach(function (field) {
                var f = state.filters[field];
                if (f.value !== '') terms.push(field + ':' + f.op + ':' + f.value);
            });
            if (terms.length > 0) params.set('filter', terms.join(';'));
            if (state.perPage !== null) params.set('perPage', String(state.perPage));
            // Pagination params follow the SERVER-declared mode only: an
            // explicit ?page= on a collection the server windowed by cursor
            // is a typed 400, so a fresh view sends neither and adopts the
            // mode from the response.
            if (state.mode === 'page' && state.page > 1) params.set('page', String(state.page));
            if (state.mode === 'cursor' && state.cursor !== '') params.set('cursor', state.cursor);
            return params;
        }

        // ---- data flow -------------------------------------------------
        function pull() {
            if (state.pulling) {
                // A view change landed mid-flight — re-pull when this request
                // settles so the rendered grid matches the FINAL view, never
                // the one that happened to be loading.
                state.pullAgain = true;
                return;
            }
            state.pulling = true;
            root.setAttribute('data-ui-grid-v2-state', 'loading');
            var qs = buildQuery().toString();
            var url = qs === '' ? endpoint : endpoint + '?' + qs;
            // No explicit Accept header: the route's content negotiation maps
            // `Accept: application/json` to the page-JSON projection; the
            // default `*/*` yields the canonical `{data, meta}` envelope.
            fetch(url, { method: 'GET', credentials: 'same-origin' })
                .then(function (res) {
                    return res.json().then(function (body) { return { ok: res.ok, body: body }; });
                })
                .then(function (result) {
                    state.pulling = false;
                    if (drainQueuedPull()) return;
                    if (!result.ok || !result.body || !Array.isArray(result.body.data)) {
                        handleErrorEnvelope(result.body);
                        return;
                    }
                    state.recovered = false;
                    render(result.body);
                })
                .catch(function () {
                    state.pulling = false;
                    if (drainQueuedPull()) return;
                    showError('The grid feed is unreachable.');
                });
        }

        // Settle path for a refresh that arrived during an in-flight pull:
        // the stale response is discarded and the FINAL view is fetched.
        function drainQueuedPull() {
            if (!state.pullAgain) return false;
            state.pullAgain = false;
            pull();
            return true;
        }

        // ---- One Way Phase 4: SSE transport -----------------------------
        // start() decides the transport ONCE from the contract: SSE when the
        // route advertises it and the browser can; plain pull otherwise.
        function start() {
            if (sseAdvertised && typeof window.EventSource !== 'undefined') {
                state.transport = 'sse';
                openStream();
            } else {
                state.transport = 'pull';
                pull();
            }
        }

        // The ONLY place a connection is created. Called once from start()
        // and again ONLY on an actual transport drop — NEVER on a view
        // change (those are re-hydrate COMMANDS on the open stream).
        function openStream() {
            if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null; }
            if (source) { try { source.close(); } catch (e) {} source = null; }
            // Re-gate on every (re)connect: the OLD id is dead; the new
            // connection mints a fresh server id adopted below.
            state.streamId = null;
            state.gotFrame = false;
            root.setAttribute('data-ui-grid-v2-state', 'loading');
            var qs = buildQuery().toString();
            source = new EventSource(qs === '' ? endpoint : endpoint + '?' + qs, { withCredentials: true });

            // Adopt the server-minted id — the ONLY assignment to streamId.
            source.addEventListener('ui.stream.id', function (ev) {
                try {
                    var d = JSON.parse(ev.data);
                    if (d && typeof d.stream_id === 'string' && d.stream_id) {
                        state.streamId = d.stream_id;
                    }
                } catch (e) { /* malformed id frame → commands stay gated */ }
            });

            // A data frame IS the canonical `{data, meta}` envelope (plus the
            // `_type` discriminator) — same render path as a pull body.
            source.addEventListener('ui.collection.data', function (ev) {
                state.gotFrame = true;
                state.everStreamed = true;
                state.reconnectAttempts = 0;
                try {
                    var envelope = JSON.parse(ev.data);
                    if (!envelope || !Array.isArray(envelope.data)) {
                        handleErrorEnvelope(envelope);
                        return;
                    }
                    state.recovered = false;
                    refs.error.setAttribute('hidden', '');
                    render(envelope);
                } catch (e) { showError('Bad frame: ' + e.message); }
            });

            source.addEventListener('ui.collection.error', function (ev) {
                state.gotFrame = true;
                state.everStreamed = true;
                try { handleErrorEnvelope(JSON.parse(ev.data)); }
                catch (e) { showError('The grid stream reported an error.'); }
            });

            source.onerror = function () {
                if (!state.gotFrame && !state.everStreamed) {
                    // NEVER delivered a frame on ANY connection → SSE is not
                    // usable here; documented PERMANENT degrade to plain JSON
                    // pull. A reconnect attempt that errors before its first
                    // frame (server mid-restart) is NOT that case — the
                    // endpoint already proved it can stream, so it stays on
                    // the backoff path below.
                    if (source) { try { source.close(); } catch (e) {} source = null; }
                    state.transport = 'pull';
                    state.streamId = null;
                    pull();
                    return;
                }
                // Had a live stream and it dropped → reconnect the SAME
                // logical stream (latest view) with exponential backoff.
                if (source) { try { source.close(); } catch (e) {} source = null; }
                scheduleReconnect();
            };
        }

        function scheduleReconnect() {
            if (reconnectTimer) return;
            var delay = Math.min(30000, 1000 * Math.pow(2, state.reconnectAttempts));
            state.reconnectAttempts += 1;
            reconnectTimer = setTimeout(function () { reconnectTimer = null; openStream(); }, delay);
        }

        // One-URL re-hydrate: the view-change command POSTs the SAME endpoint
        // the EventSource holds open, distinguished by the
        // `X-Semitexa-Stream-Rehydrate` header, carrying the adopted stream
        // id + the COMPLETE canonical view state + the CSRF token.
        // Fire-and-forget, ack-only — the fresh envelope arrives on the open
        // stream as a `ui.collection.data` frame, never on this POST.
        function sendRehydrate() {
            if (!state.streamId) return; // hard gate: no command before adoption
            root.setAttribute('data-ui-grid-v2-state', 'loading');
            var terms = [];
            Object.keys(state.filters).forEach(function (field) {
                var f = state.filters[field];
                if (f.value !== '') terms.push(field + ':' + f.op + ':' + f.value);
            });
            var body = {
                stream_id: state.streamId,
                q: state.q,
                sort: state.sort,
                filter: terms.join(';'),
                perPage: state.perPage === null ? '' : String(state.perPage),
                page: (state.mode === 'page' && state.page > 1) ? String(state.page) : '',
                cursor: (state.mode === 'cursor') ? state.cursor : '',
            };
            fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: withCsrf('POST', {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Semitexa-Stream-Rehydrate': '1',
                }),
                body: JSON.stringify(body),
            }).catch(function () { /* a dropped command is retried by the next view change */ });
        }

        // Every view change funnels here: a re-hydrate command on a live
        // stream, a classic re-fetch otherwise (pull transport, or SSE not
        // yet adopted/degraded).
        function refresh() {
            if (state.transport === 'sse' && state.streamId) {
                sendRehydrate();
                return;
            }
            pull();
        }

        function handleErrorEnvelope(body) {
            var code = body && typeof body.error === 'string' ? body.error : '';
            // Auto-recover once from a pagination-state mismatch (e.g. the
            // collection crossed the auto-mode countThreshold underneath a
            // stored page/cursor): reset to the first view and re-pull.
            if (code === 'invalid_pagination' && !state.recovered) {
                state.recovered = true;
                state.mode = null;
                resetView();
                refresh();
                return;
            }
            showError(body && typeof body.message === 'string' ? body.message : 'The grid feed returned an error.');
        }

        function showError(message) {
            root.setAttribute('data-ui-grid-v2-state', 'error');
            refs.error.textContent = message;
            refs.error.removeAttribute('hidden');
        }

        function invokeAction(btn) {
            var route = btn.getAttribute('data-ui-grid-action-route') || '';
            var method = (btn.getAttribute('data-ui-grid-action-method') || 'POST').toUpperCase();
            // Same-origin guard: action routes come from the contract (which
            // may be served from the sessionStorage cache) or the page-local
            // overlay — only a root-relative, non-protocol-relative route may
            // ever carry the CSRF token, and only over a mutating verb.
            if (!/^\/(?![\/\\])/.test(route)) return;
            if (method !== 'POST' && method !== 'PUT' && method !== 'PATCH' && method !== 'DELETE') return;
            btn.disabled = true;
            fetch(route, {
                method: method,
                credentials: 'same-origin',
                headers: withCsrf(method, {}),
            }).then(function (res) {
                btn.disabled = false;
                if (!res.ok) {
                    showError('Action failed (' + res.status + ').');
                    return;
                }
                // Live stream → the write's ui.invalidate publish re-runs the
                // feed and the fresh frame arrives on the open fd; nothing to
                // do here. Pull transport → one re-pull of the current view.
                if (!(state.transport === 'sse' && state.streamId)) pull();
            }).catch(function () {
                btn.disabled = false;
                showError('Action failed: network error.');
            });
        }

        // ---- rendering ---------------------------------------------------
        function render(envelope) {
            var rows = envelope.data;
            var meta = envelope.meta || {};
            var pagination = meta.pagination || {};

            // Mode is authoritative from the envelope — never inferred from
            // the presence of nextCursor.
            state.mode = typeof pagination.mode === 'string' ? pagination.mode : null;
            if (state.mode === 'page' && typeof pagination.page === 'number') state.page = pagination.page;
            state.nextCursor = (state.mode === 'cursor' && typeof pagination.nextCursor === 'string') ? pagination.nextCursor : '';

            refs.error.setAttribute('hidden', '');
            upgradeFilterControls(meta.filterOptions);
            renderRows(rows);
            renderSortIndicators();
            renderPaginationText(rows.length, pagination);
            renderPager(pagination);

            if (rows.length === 0) {
                refs.empty.removeAttribute('hidden');
                refs.tableWrap.setAttribute('hidden', '');
            } else {
                refs.empty.setAttribute('hidden', '');
                refs.tableWrap.removeAttribute('hidden');
            }
            root.setAttribute('data-ui-grid-v2-state', 'ready');
        }

        // One Way Phase 5: server-fed select options. When the envelope's
        // `meta.filterOptions` carries an option list for a declared filter
        // field (`{ field: [{value, label}] }`), that field's control
        // upgrades from the default free-text input to a <select> — and the
        // list stays fresh on every frame (the server recomputes labels/
        // counts per request). The user's current value survives the
        // rebuild; a value the new list no longer offers falls back to ''
        // (no filter). Fields without options stay text inputs.
        function upgradeFilterControls(filterOptions) {
            if (!filterOptions || typeof filterOptions !== 'object') return;
            Object.keys(refs.filterInputs || {}).forEach(function (field) {
                var list = filterOptions[field];
                if (!Array.isArray(list) || list.length === 0) return;
                var control = refs.filterInputs[field];
                var current = control.value;
                if (control.tagName !== 'SELECT') {
                    var select = el('select', {
                        name: field,
                        'data-ui-grid-filter': field,
                        style: INPUT_STYLE,
                    });
                    control.parentNode.replaceChild(select, control);
                    refs.filterInputs[field] = select;
                    control = select;
                } else {
                    while (control.firstChild) control.removeChild(control.firstChild);
                }
                var hasCurrent = false;
                list.forEach(function (opt) {
                    if (!opt || opt.value == null || opt.label == null) return;
                    var value = String(opt.value);
                    if (value === current) hasCurrent = true;
                    control.appendChild(el('option', { value: value }, String(opt.label)));
                });
                control.value = hasCurrent ? current : '';
            });
        }

        function renderRows(rows) {
            while (refs.tbody.firstChild) refs.tbody.removeChild(refs.tbody.firstChild);
            var idField = (contract.output && typeof contract.output.idField === 'string') ? contract.output.idField : null;
            rows.forEach(function (row) {
                var tr = el('tr', { style: 'border-top:1px solid var(--ui-border-subtle);' });
                if (idField && row && row[idField] != null) {
                    tr.setAttribute('data-ui-grid-row-id', String(row[idField]));
                }
                columns.forEach(function (col) {
                    var td = el('td', { 'ui-text': 'body', style: TD_STYLE + (FORMAT_CELL_STYLES[col.format] || '') });
                    var value = row && row[col.field] != null ? String(row[col.field]) : '';
                    if (col.format === 'badge') {
                        var variant = (col.variants && col.variants[value]) || 'mute';
                        if (!BADGE_VARIANTS[variant]) variant = 'mute';
                        td.appendChild(el('span', { 'data-ui-grid-badge': variant, style: BADGE_VARIANTS[variant] }, value));
                    } else if (col.format === 'link' && col.href !== '') {
                        var href = interpolateHref(col.href, row);
                        if (href !== '') {
                            td.appendChild(el('a', { 'data-ui-grid-link': '', href: href, style: LINK_CELL_STYLE }, value));
                        } else {
                            td.textContent = value;
                        }
                    } else {
                        td.textContent = value;
                    }
                    tr.appendChild(td);
                });
                refs.tbody.appendChild(tr);
            });
        }

        function renderSortIndicators() {
            Object.keys(refs.sortHeaders).forEach(function (field) {
                var header = refs.sortHeaders[field];
                if (state.sort === field) {
                    header.th.setAttribute('aria-sort', 'ascending');
                    header.indicator.textContent = '▲';
                } else if (state.sort === '-' + field) {
                    header.th.setAttribute('aria-sort', 'descending');
                    header.indicator.textContent = '▼';
                } else {
                    header.th.setAttribute('aria-sort', 'none');
                    header.indicator.textContent = '↕';
                }
            });
        }

        function renderPaginationText(rowCount, pagination) {
            var text = 'Showing ' + rowCount + ' row' + (rowCount === 1 ? '' : 's');
            if (typeof pagination.total === 'number') text += ' of ' + pagination.total;
            if (state.mode === 'page' && typeof pagination.pageCount === 'number') {
                text += ' · page ' + state.page + ' of ' + pagination.pageCount;
            }
            text += '.';
            refs.paginationText.textContent = text;
        }

        function renderPager(pagination) {
            var nav = refs.pagination;
            while (nav.firstChild) nav.removeChild(nav.firstChild);
            nav.setAttribute('data-ui-grid-pagination-mode', state.mode || 'none');

            if (state.mode === 'page') {
                renderPagePager(nav, pagination);
            } else if (state.mode === 'cursor') {
                renderCursorPager(nav, pagination);
            }
            // mode null / 'single': no pager affordances at all.
        }

        // Page mode: numbered page buttons in a sliding window + prev/next
        // derived from hasPrevious/hasNext.
        function renderPagePager(nav, pagination) {
            var pageCount = typeof pagination.pageCount === 'number' ? pagination.pageCount : 1;
            if (pageCount <= 1) return;

            var prev = el('button', { type: 'button', 'data-ui-grid-prev': '', style: BUTTON_STYLE }, '← Previous');
            if (!pagination.hasPrevious) prev.setAttribute('disabled', '');
            prev.addEventListener('click', function () { goToPage(state.page - 1); });
            nav.appendChild(prev);

            var half = Math.floor(pageWindow / 2);
            var start = Math.max(1, Math.min(state.page - half, pageCount - pageWindow + 1));
            var end = Math.min(pageCount, start + pageWindow - 1);
            var pages = el('span', { 'data-ui-grid-pages': '', style: 'display:inline-flex;flex-wrap:wrap;gap:0.25rem;' });
            for (var n = start; n <= end; n++) {
                (function (pageNumber) {
                    var btn = el('button', { type: 'button', 'data-ui-grid-page': String(pageNumber), style: BUTTON_STYLE }, String(pageNumber));
                    if (pageNumber === state.page) {
                        btn.setAttribute('aria-current', 'page');
                        btn.setAttribute('disabled', '');
                        btn.style.fontWeight = '700';
                    } else {
                        btn.addEventListener('click', function () { goToPage(pageNumber); });
                    }
                    pages.appendChild(btn);
                })(n);
            }
            nav.appendChild(pages);

            var next = el('button', { type: 'button', 'data-ui-grid-next': '', style: BUTTON_STYLE }, 'Next →');
            if (!pagination.hasNext) next.setAttribute('disabled', '');
            next.addEventListener('click', function () { goToPage(state.page + 1); });
            nav.appendChild(next);
        }

        // Cursor mode: NO page-number affordances — Previous comes from the
        // client-side cursor trail, Next from the server-minted nextCursor.
        function renderCursorPager(nav, pagination) {
            var prev = el('button', { type: 'button', 'data-ui-grid-prev': '', style: BUTTON_STYLE }, '← Previous');
            if (state.cursorIndex === 0) prev.setAttribute('disabled', '');
            prev.addEventListener('click', function () {
                if (state.cursorIndex === 0) return;
                state.cursorIndex -= 1;
                state.cursor = state.cursorTrail[state.cursorIndex];
                refresh();
            });
            nav.appendChild(prev);

            nav.appendChild(el('span', { 'data-ui-grid-page-indicator': '', 'ui-text': 'muted', style: 'font-size:0.8125rem;' },
                'View ' + (state.cursorIndex + 1)));

            var next = el('button', { type: 'button', 'data-ui-grid-next': '', style: BUTTON_STYLE }, 'Next →');
            if (!pagination.hasNext || state.nextCursor === '') next.setAttribute('disabled', '');
            next.addEventListener('click', function () {
                if (state.nextCursor === '') return;
                state.cursorIndex += 1;
                state.cursorTrail = state.cursorTrail.slice(0, state.cursorIndex);
                state.cursorTrail.push(state.nextCursor);
                state.cursor = state.nextCursor;
                refresh();
            });
            nav.appendChild(next);
        }

        function goToPage(pageNumber) {
            if (pageNumber < 1) return;
            state.page = pageNumber;
            refresh();
        }

        // Expose for diagnostics + E2E assertions.
        root.__uiGridV2 = { contract: contract, state: state, pull: pull, refresh: refresh };
        return { start: start };
    }

    // ------------------------------------------------------------------
    // Boot — initial scan + late-arriving shells (deferred SSR blocks).
    // ------------------------------------------------------------------
    function bootAll(scope) {
        var rootNode = (scope && typeof scope.querySelectorAll === 'function') ? scope : document;
        if (rootNode.matches && rootNode.matches('[data-ui-grid-v2]')) bootGrid(rootNode);
        var roots = rootNode.querySelectorAll('[data-ui-grid-v2]');
        for (var i = 0; i < roots.length; i++) bootGrid(roots[i]);
    }

    window.SemitexaUi.gridV2 = { bootAll: bootAll };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { bootAll(); }, { once: true });
    } else {
        bootAll();
    }

    document.addEventListener('semitexa:component:rendered', function (event) {
        var element = event && event.detail && event.detail.element instanceof Element ? event.detail.element : document;
        bootAll(element);
    });
    document.addEventListener('semitexa:block:rendered', function (event) {
        var block = event && event.detail && event.detail.block instanceof Element ? event.detail.block : document;
        bootAll(block);
    });

    if (typeof MutationObserver !== 'undefined' && document.body) {
        var observer = new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                var added = mutations[i].addedNodes;
                if (!added || !added.length) continue;
                for (var j = 0; j < added.length; j++) {
                    var node = added[j];
                    if (!node || node.nodeType !== 1) continue;
                    if (node.matches && node.matches('[data-ui-grid-v2]')) {
                        bootGrid(node);
                    } else if (node.querySelectorAll) {
                        var gridRoots = node.querySelectorAll('[data-ui-grid-v2]');
                        for (var k = 0; k < gridRoots.length; k++) bootGrid(gridRoots[k]);
                    }
                }
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }
})();
