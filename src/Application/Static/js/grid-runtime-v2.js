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
 * contract advertises `modes: [... 'sse']`, the grid rides the page's ONE
 * KISS stream (openFeedChannel, platform-ui/core):
 *   - subscribes through HUG by the contract's route `name`;
 *   - renders every `ui.collection.data` frame through the SAME render()
 *     path as a pull body (the frame IS the canonical `{data, meta}`
 *     envelope, `_type` aside);
 *   - sends a view change as the COMPLETE canonical view through HUG
 *     (`op: view`); fresh rows arrive on KISS, never on the POST;
 *   - pulls the feed's plain JSON GET when the page has no KISS session.
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
// ES module: CSRF plumbing + the live-feed transport arrive through the
// import map ('platform-ui/core' -> fingerprinted URL); the import graph
// guarantees the core is initialized before this executes.
import { withCsrf, openFeedChannel, mount } from 'platform-ui/core';

(function () {
    'use strict';

    window.SemitexaUi = window.SemitexaUi || {};
    if (window.SemitexaUi.gridV2) return;

    var CONTRACT_CACHE_PREFIX = 'semitexa:ui-grid-contract:';
    var DEFAULT_PAGE_WINDOW = 7;

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
            // Read the (empty) 304 body before answering from the cache: a
            // response left unread is torn down as an aborted request, which
            // every reload of a grid page then reported as a failed OPTIONS.
            if (res.status === 304 && cached) return res.text().then(function () { return cached.contract; });
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
                    labels: col.labels && typeof col.labels === 'object' ? col.labels : null,
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
    // Safe styling primitives. Skin tokens only: an earlier set leaned on
    // --ui-action-primary and --ui-state-*-surface, which no skin defines, so
    // their light hex fallbacks always won — and controls without a background
    // took the browser's grey in dark mode.
    // ------------------------------------------------------------------
    var TH_STYLE = 'text-align:left;padding:0.5rem 0.75rem;font-size:0.75rem;letter-spacing:0.04em;text-transform:uppercase;';
    var TD_STYLE = 'padding:0.5rem 0.75rem;font-size:0.8125rem;';
    var BUTTON_STYLE = 'padding:0.25rem 0.625rem;border:1px solid var(--ui-border-subtle);background:var(--ui-surface-raised);border-radius:var(--ui-radius-sm);font-size:0.8125rem;cursor:pointer;color:inherit;';
    var INPUT_STYLE = 'padding:0.5rem 0.75rem;border:1px solid var(--ui-border-subtle);border-radius:var(--ui-radius-sm);font-size:0.875rem;background:var(--ui-surface-raised);color:var(--ui-text-primary);';
    var BADGE_BASE = 'display:inline-block;padding:0.125rem 0.5rem;border-radius:999px;font-size:0.75rem;font-weight:600;line-height:1.4;';
    function toneBadge(tone) {
        return BADGE_BASE + 'background:color-mix(in oklab, var(' + tone + ') 13%, var(--ui-surface-raised));color:color-mix(in oklab, var(' + tone + ') 62%, var(--ui-text-primary));';
    }
    // Badge variants by tone (a field type's option tone); ok / warn / mute are
    // the older names, kept so existing contracts render as before.
    var BADGE_VARIANTS = {
        success: toneBadge('--ui-state-success'),
        warning: toneBadge('--ui-state-warning'),
        danger: toneBadge('--ui-state-danger'),
        info: toneBadge('--ui-state-info'),
        brand: toneBadge('--ui-accent-brand'),
        neutral: BADGE_BASE + 'background:var(--ui-surface-sunken);color:var(--ui-text-muted);',
    };
    BADGE_VARIANTS.ok = BADGE_VARIANTS.success;
    BADGE_VARIANTS.warn = BADGE_VARIANTS.warning;
    BADGE_VARIANTS.mute = BADGE_VARIANTS.neutral;
    var LINK_CELL_STYLE = 'color:var(--ui-accent-brand);text-decoration:underline;';
    var FORMAT_CELL_STYLES = {
        datetime: 'white-space:nowrap;',
        date: 'white-space:nowrap;',
        number: 'text-align:end;font-variant-numeric:tabular-nums;',
        boolean: 'text-align:center;',
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

    // A stored moment (UTC ISO) or date, formatted for the visitor's locale but
    // in UTC — the zone it was entered and stored in — with the zone shown and
    // the machine value kept in <time datetime>. Unparseable values stay as written.
    function timeCell(value, format) {
        var dateOnly = format === 'date' && /^\d{4}-\d{2}-\d{2}$/.test(value);
        // Without a zone ("2026-05-31 06:39") a browser reads LOCAL time; stored
        // values are UTC, so say so before parsing.
        var zoneless = /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2}(\.\d+)?)?$/.test(value);
        var parsed = new Date(dateOnly ? value + 'T00:00:00Z' : (zoneless ? value.replace(' ', 'T') + 'Z' : value));
        if (isNaN(parsed.getTime())) return document.createTextNode(value);
        var options = dateOnly
            ? { dateStyle: 'medium', timeZone: 'UTC' }
            : { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: 'UTC', timeZoneName: 'short' };
        var text;
        try { text = new Intl.DateTimeFormat(undefined, options).format(parsed); } catch (e) { text = value; }
        return el('time', { datetime: value, title: value }, text);
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
        // A backslash counts as a slash in a URL: `/\evil.test` is `//evil.test`.
        if (!/^\/(?![\/\\])/.test(endpoint)) {
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

        // Page-local row actions: [{label, href?} | {label, route, method?, confirm?}],
        // `{field}` placeholders filled from the row.
        var rowActions = [];
        var rowActionsRaw = root.getAttribute('data-ui-grid-row-actions');
        if (rowActionsRaw) {
            try {
                var parsedRow = JSON.parse(rowActionsRaw);
                if (Array.isArray(parsedRow)) {
                    rowActions = parsedRow.filter(function (a) {
                        return a && typeof a.label === 'string' && (typeof a.href === 'string' || typeof a.route === 'string');
                    });
                }
            } catch (e) { /* malformed page-local overlay — ignore */ }
        }

        // Server actions (row / bulk / header), run through HUG by the grid's
        // #[AsGridAction]; the list is also signed into the grid's event
        // context, so this copy only decides what to draw.
        var serverActions = [];
        var serverRaw = root.getAttribute('data-ui-grid-server-actions');
        if (serverRaw && root.getAttribute('data-ui-component-instance-id')) {
            try {
                var parsedServer = JSON.parse(serverRaw);
                if (Array.isArray(parsedServer)) {
                    serverActions = parsedServer.filter(function (a) {
                        return a && typeof a.id === 'string' && typeof a.label === 'string' && Array.isArray(a.scopes);
                    });
                }
            } catch (e) { /* malformed — no server actions */ }
        }

        root.setAttribute('data-ui-grid-v2-state', 'loading');

        loadContract(endpoint).then(function (contract) {
            var instance = createInstance(root, gridId, endpoint, contract, shellActions, emptyMessage, rowActions, serverActions);
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

    function createInstance(root, gridId, endpoint, contract, shellActions, emptyMessage, rowActions, serverActions) {
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
        serverActions = serverActions || [];
        var inScope = function (scope) {
            return serverActions.filter(function (a) { return a.scopes.indexOf(scope) >= 0; });
        };
        var serverRow = inScope('row');
        var serverBulk = inScope('bulk');
        var serverHeader = inScope('header');
        var gridIdField = (contract.output && typeof contract.output.idField === 'string') ? contract.output.idField
            : ((contract.ui && typeof contract.ui.idField === 'string') ? contract.ui.idField : null);
        // Row selection exists only for a bulk action, over rows that have an id.
        var selectable = serverBulk.length > 0 && gridIdField !== null;
        var selected = {};          // id → true, only ids on the current page
        var pendingAction = null;   // {scope, timer} while an action runs
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
            restorePage: null,    // ?<ns>-page from the URL, applied once the mode is known
            pulling: false,
            pullAgain: false,     // a refresh landed while a pull was in flight
            recovered: false,     // one-shot guard for invalid_pagination auto-recovery
            // --- One Way Phase 4: SSE transport state -------------------
            transport: null,      // 'sse' | 'pull' — decided in start(); sse→pull without a KISS session
            subscriptionId: null, // this grid's subscription on the page's KISS stream
        };
        var channel = null;         // openFeedChannel handle
        var sseAdvertised = Array.isArray(contract.modes) && contract.modes.indexOf('sse') >= 0
            && typeof contract.name === 'string' && contract.name !== '';
        // A field offering both gte and lte is a range ("from" / "to");
        // every other filter is one control with its default operator.
        state.ranges = {};
        var isRange = function (operators) {
            return Array.isArray(operators) && operators.indexOf('gte') >= 0 && operators.indexOf('lte') >= 0;
        };
        Object.keys(filterFields).forEach(function (field) {
            if (isRange(filterFields[field])) state.ranges[field] = { gte: '', lte: '' };
            else state.filters[field] = { op: defaultOperatorFor(filterFields[field]), value: '' };
        });

        /** Every filter term of the current view, as the wire syntax wants them. */
        function filterTerms() {
            var terms = [];
            Object.keys(state.filters).forEach(function (field) {
                var f = state.filters[field];
                if (f.value !== '') terms.push(field + ':' + f.op + ':' + f.value);
            });
            Object.keys(state.ranges).forEach(function (field) {
                ['gte', 'lte'].forEach(function (op) {
                    if (state.ranges[field][op] !== '') terms.push(field + ':' + op + ':' + state.ranges[field][op]);
                });
            });
            return terms;
        }

        // ---- URL state (opt-in: data-ui-grid-url="<namespace>") ----------
        // The view lives in the address bar as `<ns>-q`, `<ns>-sort`,
        // `<ns>-filter`, `<ns>-perPage`, `<ns>-page`: restored on load — a
        // link is attacker input, so each value must fit THIS grid's contract
        // (the feed validates again) — and rewritten after every render.
        var urlNs = root.getAttribute('data-ui-grid-url') || '';
        if (!/^[A-Za-z][A-Za-z0-9_]{0,23}$/.test(urlNs)) urlNs = '';
        restoreFromUrl();

        // ---- skeleton -------------------------------------------------
        var refs = buildSkeleton();
        if (refs.search) refs.search.value = state.q;
        Object.keys(refs.filterInputs || {}).forEach(function (field) {
            refs.filterInputs[field].value = state.filters[field].value;
        });
        Object.keys(refs.rangeInputs || {}).forEach(function (field) {
            refs.rangeInputs[field].gte.value = state.ranges[field].gte;
            refs.rangeInputs[field].lte.value = state.ranges[field].lte;
        });

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
                || Object.keys(state.ranges).length > 0
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

                r.rangeInputs = {};
                Object.keys(state.ranges).forEach(function (field) {
                    var overlay = (ui.filters && ui.filters[field]) || {};
                    var label = typeof overlay.label === 'string' ? overlay.label : humanizeFieldName(field);
                    var kind = overlay.input === 'date' ? 'date' : (overlay.input === 'number' ? 'number' : 'text');
                    var group = el('fieldset', { 'data-ui-grid-filter-range': field, 'sx-layout': 'stack', 'sx-gap': '1', style: 'border:0;margin:0;padding:0;min-width:12rem;' });
                    group.appendChild(el('legend', { 'ui-text': 'label', style: 'font-size:0.75rem;letter-spacing:0.04em;text-transform:uppercase;padding:0;' }, label));
                    var row = el('div', { 'sx-layout': 'cluster', 'sx-gap': '1', style: 'flex-wrap:nowrap;' });
                    var pair = {};
                    [['gte', 'from'], ['lte', 'to']].forEach(function (end) {
                        var input = el('input', {
                            type: kind,
                            'data-ui-grid-range': end[0],
                            'aria-label': label + ' ' + end[1],
                            placeholder: end[1],
                            style: INPUT_STYLE + 'width:8.5rem;',
                        });
                        if (kind === 'number') input.setAttribute('step', 'any');
                        row.appendChild(input);
                        pair[end[0]] = input;
                    });
                    group.appendChild(row);
                    r.form.appendChild(group);
                    r.rangeInputs[field] = pair;
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

                if (search !== null || Object.keys(state.filters).length > 0 || Object.keys(state.ranges).length > 0) {
                    r.form.appendChild(el('button', {
                        type: 'submit',
                        style: 'padding:0.5rem 1rem;border:0;border-radius:var(--ui-radius-sm);background:var(--ui-accent-brand);color:var(--ui-text-on-accent);font-size:0.875rem;cursor:pointer;',
                    }, 'Apply'));
                    var clear = el('button', { type: 'button', 'data-ui-grid-clear': '', 'ui-text': 'muted', style: 'font-size:0.875rem;border:0;background:none;cursor:pointer;' }, 'Clear');
                    clear.addEventListener('click', function () {
                        if (r.search) r.search.value = '';
                        Object.keys(r.filterInputs || {}).forEach(function (f) { r.filterInputs[f].value = ''; });
                        Object.keys(r.rangeInputs || {}).forEach(function (f) { r.rangeInputs[f].gte.value = ''; r.rangeInputs[f].lte.value = ''; });
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

            if (actions.length > 0 || serverHeader.length > 0) {
                var toolbar = el('div', { 'data-ui-grid-toolbar': '', 'sx-layout': 'cluster', 'sx-gap': '3', 'sx-align': 'center', style: 'flex-wrap:wrap;' });
                serverHeader.forEach(function (action) {
                    var hbtn = serverButton(action, { style: 'font-size:0.8125rem;' });
                    hbtn.addEventListener('click', function () { runServerAction(action, 'header', [], null); });
                    toolbar.appendChild(hbtn);
                });
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

            if (selectable) {
                r.bulkBar = el('div', {
                    'data-ui-grid-bulk': '', hidden: '', role: 'region', 'aria-label': 'Selected rows',
                    'sx-surface': 'panel', 'sx-padding': '2', 'sx-radius': 'md', 'sx-layout': 'cluster', 'sx-gap': '2', 'sx-align': 'center',
                    style: 'flex-wrap:wrap;border-left:4px solid var(--ui-accent-brand);',
                });
                r.bulkCount = el('span', { 'data-ui-grid-bulk-count': '', 'ui-text': 'label', 'aria-live': 'polite' });
                r.bulkBar.appendChild(r.bulkCount);
                serverBulk.forEach(function (action) {
                    var bbtn = serverButton(action, {});
                    bbtn.addEventListener('click', function () { runServerAction(action, 'bulk', selectedIds(), null); });
                    r.bulkBar.appendChild(bbtn);
                });
                var clearSel = el('button', { type: 'button', 'data-ui-grid-bulk-clear': '', style: BUTTON_STYLE + 'margin-left:auto;' }, 'Clear selection');
                clearSel.addEventListener('click', function () { selected = {}; syncSelection(); });
                r.bulkBar.appendChild(clearSel);
                root.appendChild(r.bulkBar);
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
            if (selectable) {
                var selTh = el('th', { style: TH_STYLE + 'width:2.5rem;' });
                r.selectAll = el('input', { type: 'checkbox', 'data-ui-grid-select-all': '', 'aria-label': 'Select every row on this page' });
                r.selectAll.addEventListener('change', function () {
                    var on = r.selectAll.checked;
                    selected = {};
                    if (on) currentIds().forEach(function (id) { selected[id] = true; });
                    syncSelection();
                });
                selTh.appendChild(r.selectAll);
                headRow.appendChild(selTh);
            }
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
            if (rowActions.length > 0 || serverRow.length > 0) {
                headRow.appendChild(el('th', { 'ui-text': 'label', style: TH_STYLE }, 'Actions'));
            }
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

        function restoreFromUrl() {
            if (urlNs === '') return;
            var params = new URLSearchParams(window.location.search);
            var read = function (name) { return params.get(urlNs + '-' + name); };
            var q = read('q');
            if (q !== null && q.length <= 100) state.q = q.trim();
            var sort = read('sort');
            if (sort !== null && sortFields.indexOf(sort.replace(/^-/, '')) >= 0) state.sort = sort;
            var filter = read('filter');
            if (filter !== null && filter.length <= 1000) {
                filter.split(';').forEach(function (term) {
                    var m = /^([A-Za-z_][A-Za-z0-9_]*):([a-z]+):(.{1,100})$/.exec(term);
                    if (m && state.filters[m[1]]) state.filters[m[1]] = { op: m[2], value: m[3] };
                    else if (m && state.ranges[m[1]] && (m[2] === 'gte' || m[2] === 'lte')) state.ranges[m[1]][m[2]] = m[3];
                });
            }
            var perPage = parseInt(read('perPage') || '', 10);
            var options = paginationPolicy.perPageOptions;
            if (perPage > 0 && (!Array.isArray(options) || options.indexOf(perPage) >= 0)) state.perPage = perPage;
            var page = read('page');
            // The pagination mode is known only from the first answer: the page
            // is asked for once the feed says it pages by number.
            if (page !== null && /^[1-9]\d{0,5}$/.test(page)) state.restorePage = parseInt(page, 10);
        }

        function writeToUrl() {
            if (urlNs === '') return;
            var url = new URL(window.location.href);
            var set = function (name, value) {
                if (value === null || value === '') url.searchParams.delete(urlNs + '-' + name);
                else url.searchParams.set(urlNs + '-' + name, value);
            };
            var terms = filterTerms();
            set('q', state.q);
            set('sort', state.sort === defaultSort ? null : state.sort);
            set('filter', terms.join(';'));
            set('perPage', state.perPage === paginationPolicy.defaultPerPage || state.perPage === null ? null : String(state.perPage));
            set('page', state.mode === 'page' && state.page > 1 ? String(state.page) : null);
            var target = url.pathname + url.search + url.hash;
            if (target === window.location.pathname + window.location.search + window.location.hash) return;
            var nav = window.SemitexaNavigation;
            if (nav && typeof nav.recordUrl === 'function' && nav.isShellPage()) nav.recordUrl(target, { replace: true });
            else window.history.replaceState(window.history.state, '', target);
        }

        // ---- state helpers --------------------------------------------
        function readControls() {
            if (refs.search) state.q = refs.search.value.trim();
            Object.keys(refs.filterInputs || {}).forEach(function (field) {
                state.filters[field].value = refs.filterInputs[field].value.trim();
            });
            Object.keys(refs.rangeInputs || {}).forEach(function (field) {
                state.ranges[field].gte = refs.rangeInputs[field].gte.value.trim();
                state.ranges[field].lte = refs.rangeInputs[field].lte.value.trim();
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
            var terms = filterTerms();
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
            // Say JSON: a feed route may also serve its page as text/html (one
            // route per screen), and `*/*` would then get the page.
            fetch(url, { method: 'GET', credentials: 'same-origin', headers: { Accept: 'application/json' } })
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
        // start() decides the transport ONCE from the contract: the page's KISS
        // stream when the route advertises SSE, plain pull otherwise. The
        // subscription itself lives in openFeedChannel (platform-ui/core).
        function start() {
            if (!sseAdvertised) {
                state.transport = 'pull';
                pull();
                return;
            }
            state.transport = 'sse';
            channel = openFeedChannel({
                feed: contract.name,
                params: currentViewParams,
                dataEvent: 'ui.collection.data',
                errorEvent: 'ui.collection.error',
                patchEvent: 'ui.collection.patch',
                onData: onDataEnvelope,
                onPatch: onPatchFrame,
                onError: function (envelope) {
                    if (!envelope) { showError('The grid stream reported an error.'); return; }
                    handleErrorEnvelope(envelope);
                },
                onStreamId: function (id) { state.subscriptionId = id; },
                onPull: function () {
                    // No KISS session on this page: the feed's plain JSON GET.
                    state.transport = 'pull';
                    state.subscriptionId = null;
                    channel = null;
                    pull();
                }
            });
        }

        // A data frame — the body is the same canonical `{data, meta}`
        // envelope a pull body carries, so it routes through the SAME
        // render/error paths.
        function onDataEnvelope(envelope) {
            if (!envelope || !Array.isArray(envelope.data)) { handleErrorEnvelope(envelope); return; }
            state.recovered = false;
            refs.error.setAttribute('hidden', '');
            state.envelope = { data: envelope.data, meta: envelope.meta || {} };
            render(envelope);
        }

        // A keyed patch — what a live re-run changed in the page this grid was
        // last sent: rows upserted and removed by key, the new order, the meta
        // when it changed. Applied to that page; a patch that does not fit it
        // (a row it orders is not there) means the two drifted, and the grid
        // asks for its view again.
        function onPatchFrame(frame) {
            var patch = frame && frame.patch;
            var base = state.envelope;
            if (!patch || !base || typeof patch.key !== 'string' || !Array.isArray(patch.order)) { refresh(); return; }
            var byKey = {};
            base.data.forEach(function (row) { if (row && row[patch.key] != null) byKey[String(row[patch.key])] = row; });
            (patch.remove || []).forEach(function (id) { delete byKey[String(id)]; });
            (patch.upsert || []).forEach(function (row) { if (row && row[patch.key] != null) byKey[String(row[patch.key])] = row; });
            var rows = [];
            for (var i = 0; i < patch.order.length; i++) {
                var row = byKey[String(patch.order[i])];
                if (!row) { refresh(); return; }
                rows.push(row);
            }
            onDataEnvelope({ data: rows, meta: frame.meta || base.meta });
        }

        // The current view as a plain params object (q/sort/filter/perPage/page/
        // cursor) — the same coordinates buildQuery emits.
        function currentViewParams() {
            var p = {};
            buildQuery().forEach(function (value, key) { p[key] = value; });
            return p;
        }

        function isLive() {
            return state.transport === 'sse' && state.subscriptionId !== null && channel !== null;
        }

        // Every view change funnels here: the complete new view through HUG on
        // a live subscription (fresh rows arrive on KISS), a re-fetch otherwise.
        function refresh() {
            if (isLive()) {
                root.setAttribute('data-ui-grid-v2-state', 'loading');
                channel.view(currentViewParams());
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
            sendAction(btn, btn.getAttribute('data-ui-grid-action-route') || '', btn.getAttribute('data-ui-grid-action-method') || 'POST');
        }

        function rowActionsCell(row) {
            var td = el('td', { style: TD_STYLE + 'white-space:nowrap;' });
            rowActions.forEach(function (action, index) {
                if (index > 0) td.appendChild(document.createTextNode(' '));
                if (typeof action.href === 'string') {
                    var href = interpolateHref(action.href, row);
                    if (href !== '') td.appendChild(el('a', { 'data-ui-grid-row-action': action.label, href: href, style: LINK_CELL_STYLE }, action.label));
                    return;
                }
                var route = interpolateHref(action.route, row);
                if (route === '') return;
                var btn = el('button', { type: 'button', 'data-ui-grid-row-action': action.label, style: BUTTON_STYLE }, action.label);
                btn.addEventListener('click', function () {
                    var question = typeof action.confirm === 'string' ? fillText(action.confirm, row) : null;
                    (question === null ? Promise.resolve(true) : confirmAction(question, action.label)).then(function (ok) {
                        if (ok) sendAction(btn, route, typeof action.method === 'string' ? action.method : 'POST');
                    });
                });
                td.appendChild(btn);
            });
            var rowServerId = gridIdField && row && row[gridIdField] != null ? String(row[gridIdField]) : '';
            if (rowServerId !== '') {
                serverRow.forEach(function (action) {
                    if (td.firstChild) td.appendChild(document.createTextNode(' '));
                    var sbtn = serverButton(action, { 'data-ui-grid-row-action': action.label });
                    sbtn.addEventListener('click', function () { runServerAction(action, 'row', [rowServerId], row); });
                    td.appendChild(sbtn);
                });
            }
            return td;
        }

        // ---- server actions (HUG) -----------------------------------------
        function serverButton(action, attrs) {
            var danger = action.tone === 'danger';
            return el('button', Object.assign({
                type: 'button',
                'data-ui-grid-server-action': action.id,
                style: BUTTON_STYLE + (danger ? 'color:var(--ui-state-danger);border-color:currentColor;' : ''),
            }, attrs), action.label);
        }

        function currentIds() {
            var ids = [];
            refs.tbody.querySelectorAll('[data-ui-grid-select]').forEach(function (box) {
                ids.push(box.getAttribute('data-ui-grid-select'));
            });
            return ids;
        }

        function selectedIds() {
            return Object.keys(selected);
        }

        function syncSelection() {
            if (!selectable) return;
            var count = selectedIds().length;
            var onPage = currentIds();
            refs.tbody.querySelectorAll('[data-ui-grid-select]').forEach(function (box) {
                box.checked = selected[box.getAttribute('data-ui-grid-select')] === true;
            });
            refs.selectAll.checked = onPage.length > 0 && count === onPage.length;
            refs.selectAll.indeterminate = count > 0 && count < onPage.length;
            refs.bulkCount.textContent = count === 1 ? '1 row selected' : count + ' rows selected';
            if (count > 0) refs.bulkBar.removeAttribute('hidden'); else refs.bulkBar.setAttribute('hidden', '');
        }

        function setBusy(busy) {
            if (busy) root.setAttribute('aria-busy', 'true'); else root.removeAttribute('aria-busy');
            root.querySelectorAll('[data-ui-grid-server-action]').forEach(function (b) { b.disabled = busy; });
        }

        function runServerAction(action, scope, ids, row) {
            if (pendingAction !== null) return;
            if (scope === 'bulk' && ids.length === 0) return;
            var text = scope === 'bulk' && typeof action.confirmBulk === 'string' ? action.confirmBulk : action.confirm;
            var question = null;
            if (typeof text === 'string' && text !== '') {
                text = text.replace(/\{count\}/g, String(ids.length));
                question = row ? fillText(text, row) : text;
            }
            (question === null ? Promise.resolve(true) : confirmAction(question, action.label)).then(function (ok) {
                if (!ok) return;
                var api = window.SemitexaUi;
                var instanceId = root.getAttribute('data-ui-component-instance-id') || '';
                setBusy(true);
                // Optimistic: an action that takes its rows away hides them now.
                var gone = [];
                if (action.optimistic === 'remove' && scope !== 'header') {
                    var wanted = {};
                    ids.forEach(function (id) { wanted[String(id)] = true; });
                    Array.prototype.forEach.call(refs.tbody.children, function (tr) {
                        if (wanted[tr.getAttribute('data-ui-grid-row-id')] === true && !tr.hidden) {
                            tr.hidden = true;
                            tr.setAttribute('data-ui-optimistic-gone', '');
                            gone.push(tr);
                        }
                    });
                }
                pendingAction = {
                    scope: scope,
                    gone: gone,
                    // A refused request (tampered, expired) answers no patch;
                    // do not leave the grid busy forever.
                    timer: setTimeout(function () {
                        restoreGone(pendingAction ? pendingAction.gone : []);
                        pendingAction = null;
                        setBusy(false);
                        showError('The action did not answer. Reload the page and try again.');
                    }, 30000),
                };
                var sent = api && typeof api.dispatch === 'function' && api.dispatch({
                    instanceId: instanceId, part: 'action', event: 'invoke',
                    value: { op: action.id, scope: scope, ids: ids },
                });
                if (!sent) {
                    clearTimeout(pendingAction.timer);
                    restoreGone(pendingAction.gone);
                    pendingAction = null;
                    setBusy(false);
                    showError('This action is not available on this page.');
                }
            });
        }

        // The server answers with a toast and this event (UiResponsePatch::dispatch).
        root.addEventListener('ui-grid:action', function (event) {
            if (pendingAction === null) return;
            clearTimeout(pendingAction.timer);
            var scope = pendingAction.scope;
            var gone = pendingAction.gone || [];
            pendingAction = null;
            setBusy(false);
            var detail = event.detail || {};
            // Refused: the rows come back. Done: they stay hidden until the next
            // frame, which removes them — or shows any that are still there.
            if (!detail.ok) restoreGone(gone);
            if (detail.ok && scope === 'bulk') {
                selected = {};
                syncSelection();
            }
            // Live: the writes' invalidation re-runs the feed. Pull: re-pull once.
            if (detail.ok && !isLive()) pull();
        });

        function restoreGone(rows) {
            (rows || []).forEach(function (tr) {
                tr.hidden = false;
                tr.removeAttribute('data-ui-optimistic-gone');
            });
        }

        // Refused outright (an error answer, no network): no ui-grid:action
        // arrives, so the action ends here — the rows come back at once
        // instead of after the 30-second guard.
        var onActionFailed = function (event) {
            var captured = event.detail && event.detail.captured;
            if (pendingAction === null || !captured || captured.part !== 'action'
                || captured.instanceId !== (root.getAttribute('data-ui-component-instance-id') || '')) return;
            clearTimeout(pendingAction.timer);
            restoreGone(pendingAction.gone);
            pendingAction = null;
            setBusy(false);
            showError('The action did not go through. Nothing was changed.');
        };
        document.addEventListener('semitexa:ui-event:failed', onActionFailed);

        /** `{field}` → the row's value, as text (the dialog sets textContent). */
        function fillText(template, row) {
            return template.replace(/\{([A-Za-z0-9_]+)\}/g, function (_m, field) {
                return row && row[field] != null ? String(row[field]) : '';
            });
        }

        // One native <dialog> per grid asks before a destructive row action:
        // showModal() gives the focus trap, Escape and the inert page.
        var confirmDialog = null;
        function confirmAction(question, label) {
            if (!confirmDialog) {
                confirmDialog = el('dialog', { 'data-ui-grid-confirm': '', style: 'padding:1.25rem;border:1px solid var(--ui-border-subtle);border-radius:var(--ui-radius-md);max-width:28rem;' });
                confirmDialog.appendChild(el('p', { 'data-ui-grid-confirm-text': '', style: 'margin:0 0 1rem;' }));
                var bar = el('div', { style: 'display:flex;gap:0.5rem;justify-content:flex-end;' });
                bar.appendChild(el('button', { type: 'button', value: 'cancel', 'data-ui-grid-confirm-cancel': '', style: BUTTON_STYLE }, 'Cancel'));
                bar.appendChild(el('button', { type: 'button', value: 'ok', 'data-ui-grid-confirm-ok': '', style: BUTTON_STYLE }));
                confirmDialog.appendChild(bar);
                root.appendChild(confirmDialog);
            }
            confirmDialog.querySelector('[data-ui-grid-confirm-text]').textContent = question;
            confirmDialog.querySelector('[data-ui-grid-confirm-ok]').textContent = label;
            return new Promise(function (resolve) {
                var answer = false;
                var onClick = function (event) {
                    var value = event.target && event.target.value;
                    if (value === 'ok' || value === 'cancel') {
                        answer = value === 'ok';
                        confirmDialog.close();
                    }
                };
                confirmDialog.addEventListener('click', onClick);
                confirmDialog.addEventListener('close', function () {
                    confirmDialog.removeEventListener('click', onClick);
                    resolve(answer);
                }, { once: true });
                confirmDialog.showModal();
            });
        }

        function sendAction(btn, route, methodName) {
            var method = String(methodName).toUpperCase();
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
                if (!isLive()) pull();
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

            if (state.restorePage && state.mode === 'page') {
                var restorePage = state.restorePage;
                state.restorePage = null;
                if (restorePage > 1 && restorePage !== state.page) {
                    state.page = restorePage;
                    refresh();
                    return;
                }
            }
            state.restorePage = null;
            writeToUrl();
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

        // Keyed: a row whose content did not change keeps its <tr> (focus, a
        // checked box, an open menu stay as they are); a changed or new one is
        // built afresh and, on a live update, marked `data-ui-row-updated` for a
        // moment; a row no longer on the page goes. A row without an id is
        // always built afresh.
        function renderRows(rows) {
            // A typed resource names its id in `output`; a field-driven feed (no
            // Resource DTO) names it in its `ui` block.
            var idField = (contract.output && typeof contract.output.idField === 'string') ? contract.output.idField
                : ((contract.ui && typeof contract.ui.idField === 'string') ? contract.ui.idField : null);
            var before = {};
            var hadRows = refs.tbody.children.length > 0;
            Array.prototype.forEach.call(refs.tbody.children, function (tr) {
                var id = tr.getAttribute('data-ui-grid-row-id');
                if (id !== null && typeof tr.__uiRowSig === 'string') before[id] = tr;
            });
            // A view change (sort, page, filter) is a new page, not an update.
            var live = hadRows && root.getAttribute('data-ui-grid-v2-state') !== 'loading';
            rows.forEach(function (row, index) {
                var id = idField && row && row[idField] != null ? String(row[idField]) : null;
                var sig = JSON.stringify(row);
                var kept = id !== null ? before[id] : null;
                var tr;
                if (kept && kept.__uiRowSig === sig) {
                    tr = kept;
                    tr.removeAttribute('data-ui-row-updated');
                    // Still here after an optimistic removal: the server kept it.
                    if (tr.hasAttribute('data-ui-optimistic-gone')) {
                        tr.hidden = false;
                        tr.removeAttribute('data-ui-optimistic-gone');
                    }
                } else {
                    tr = buildRow(row, idField);
                    tr.__uiRowSig = sig;
                    if (live) {
                        tr.setAttribute('data-ui-row-updated', '');
                        flash(tr);
                    }
                }
                if (id !== null) delete before[id];
                if (refs.tbody.children[index] !== tr) refs.tbody.insertBefore(tr, refs.tbody.children[index] || null);
            });
            while (refs.tbody.children.length > rows.length) refs.tbody.removeChild(refs.tbody.lastChild);
            if (selectable) {
                // Only rows on screen stay selected: a bulk action never
                // reaches a row the visitor can no longer see.
                var onPage = {};
                currentIds().forEach(function (id) { onPage[id] = true; });
                Object.keys(selected).forEach(function (id) { if (!onPage[id]) delete selected[id]; });
                syncSelection();
            }
        }

        // A brief accent bar at the start of a row a live update changed; none
        // for who asked for less motion. A bar, not a tint behind the text: a
        // tint lowered the row's text contrast below WCAG AA while it faded.
        // Web Animations, so no style element is needed under CSP.
        function flash(tr) {
            var cell = tr.firstElementChild;
            if (!cell || typeof cell.animate !== 'function') return;
            if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
            cell.animate([
                { boxShadow: 'inset 3px 0 0 var(--ui-accent-brand)' },
                { boxShadow: 'inset 3px 0 0 transparent' }
            ], { duration: 1600, easing: 'ease-out' });
        }

        function buildRow(row, idField) {
                var tr = el('tr', { style: 'border-top:1px solid var(--ui-border-subtle);' });
                if (idField && row && row[idField] != null) {
                    tr.setAttribute('data-ui-grid-row-id', String(row[idField]));
                }
                if (selectable) {
                    var rowId = row && row[gridIdField] != null ? String(row[gridIdField]) : '';
                    var selTd = el('td', { style: TD_STYLE + 'width:2.5rem;' });
                    if (rowId !== '') {
                        var box = el('input', { type: 'checkbox', 'data-ui-grid-select': rowId, 'aria-label': 'Select row ' + rowId });
                        box.checked = selected[rowId] === true;
                        box.addEventListener('change', function () {
                            if (box.checked) selected[rowId] = true; else delete selected[rowId];
                            syncSelection();
                        });
                        selTd.appendChild(box);
                    }
                    tr.appendChild(selTd);
                }
                columns.forEach(function (col) {
                    var td = el('td', { 'ui-text': 'body', style: TD_STYLE + (FORMAT_CELL_STYLES[col.format] || '') });
                    var value = row && row[col.field] != null ? String(row[col.field]) : '';
                    if (col.format === 'badge') {
                        var variant = (col.variants && col.variants[value]) || 'neutral';
                        if (!BADGE_VARIANTS[variant]) variant = 'neutral';
                        var badgeText = col.labels && typeof col.labels[value] === 'string' ? col.labels[value] : value;
                        td.appendChild(el('span', { 'data-ui-grid-badge': variant, 'data-ui-grid-value': value, style: BADGE_VARIANTS[variant] }, badgeText));
                    } else if (col.format === 'link' && col.href !== '') {
                        var href = interpolateHref(col.href, row);
                        if (href !== '') {
                            td.appendChild(el('a', { 'data-ui-grid-link': '', href: href, style: LINK_CELL_STYLE }, value));
                        } else {
                            td.textContent = value;
                        }
                    } else if ((col.format === 'datetime' || col.format === 'date') && value !== '') {
                        td.appendChild(timeCell(value, col.format));
                    } else if (col.format === 'boolean') {
                        var yes = row[col.field] === true || value === '1' || value === 'true';
                        td.appendChild(el('span', { 'data-ui-grid-boolean': yes ? 'yes' : 'no', 'aria-label': yes ? 'Yes' : 'No', title: yes ? 'Yes' : 'No' }, yes ? '✓' : '–'));
                    } else if (col.format === 'url' && /^https?:\/\//i.test(value)) {
                        td.appendChild(el('a', { 'data-ui-grid-link': '', href: value, rel: 'noopener noreferrer', target: '_blank', style: LINK_CELL_STYLE }, value));
                    } else {
                        td.textContent = value;
                    }
                    tr.appendChild(td);
                });
                if (rowActions.length > 0 || serverRow.length > 0) tr.appendChild(rowActionsCell(row));
                return tr;
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

        // Tear down: the channel hard-unsubscribes the shared subscription (so
        // the server reaps this grid's record) and/or closes the dedicated
        // stream + pending reconnect timer.
        function destroy() {
            document.removeEventListener('semitexa:ui-event:failed', onActionFailed);
            if (channel) {
                channel.close();
                channel = null;
            }
        }

        // Expose for diagnostics + E2E assertions.
        root.__uiGridV2 = { contract: contract, state: state, pull: pull, refresh: refresh, destroy: destroy };
        return { start: start, destroy: destroy };
    }

    // ------------------------------------------------------------------
    // Boot — the one element lifecycle (core.mount): every grid shell now
    // and later (deferred blocks, navigation swaps, morphs), and teardown —
    // a grid removed from the page unsubscribes so the server reaps its
    // subscription while the shared KISS stream survives.
    // ------------------------------------------------------------------
    var grids = mount('[data-ui-grid-v2]', {
        connect: function (root) {
            bootGrid(root);
            return {
                destroy: function () {
                    if (root.__uiGridV2 && typeof root.__uiGridV2.destroy === 'function') {
                        try { root.__uiGridV2.destroy(); } catch (e) { /* noop */ }
                    }
                    root.__uiGridV2Booted = false;
                }
            };
        }
    });

    window.SemitexaUi.gridV2 = { bootAll: function (scope) { grids.scan(scope); } };
})();
