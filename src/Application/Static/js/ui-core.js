/*
 * Semitexa Platform UI — shared client core (ui-core.js).
 *
 * The ONE place for the browser-side helpers every platform-ui runtime
 * shares. Before this file existed each runtime carried its own copy of
 * HTML-escaping and CSRF plumbing (and some carried none — calendar writes
 * shipped without the X-CSRF-Token header at all). A fix here reaches every
 * runtime at once.
 *
 * Public API (window.SemitexaUi.core):
 *   .version               int
 *   .esc(value)            HTML-escape for string-built markup (&<>"')
 *   .readCsrfToken()       the non-HttpOnly XSRF-TOKEN cookie value ('' when
 *                          absent — guests have no cookie and send nothing)
 *   .withCsrf(method, h)   mutate+return headers: echoes the token back as
 *                          X-CSRF-Token on unsafe methods (double-submit,
 *                          matches CsrfListener on the server)
 *   .fetchJson(url, opts)  same-origin fetch with the platform conventions:
 *                          credentials, CSRF on unsafe methods, JSON request
 *                          body (object body → stringified), JSON response
 *                          parse. Resolves { ok, status, data }; rejects only
 *                          on network failure. data is null when the body is
 *                          empty or not JSON.
 *   .openFeedChannel(opts) the ONE live-feed transport: a feed on the
 *                          page's KISS stream, controlled through HUG by
 *                          name; plain pull without a KISS session —
 *                          see the function docblock below
 *   .onReady(fn)           run fn at DOMContentLoaded, or immediately when
 *                          the document is already parsed
 *
 * Load order: assets.json pins this file at body priority 50 — before every
 * other platform-ui runtime (60+). Pages that include runtimes by hand (e.g.
 * the OS calendar app) must load ui-core.js first; dependants fail fast with
 * an actionable console error instead of half-working.
 *
 * The namespace is created with extend-don't-replace semantics so script
 * order never silently drops another runtime's API.
 *
 * Idempotent: re-evaluating this script is a no-op.
 */
(function () {
    'use strict';

    var ns = window.SemitexaUi = window.SemitexaUi || {};
    if (ns.core) return;

    var ESC_MAP = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return ESC_MAP[c];
        });
    }

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
        var m = String(method || 'GET').toUpperCase();
        if (m !== 'GET' && m !== 'HEAD') {
            var token = readCsrfToken();
            if (token) out['X-CSRF-Token'] = token;
        }
        return out;
    }

    function fetchJson(url, opts) {
        opts = opts || {};
        var method = String(opts.method || 'GET').toUpperCase();
        var headers = { 'Accept': 'application/json' };
        if (opts.headers) {
            for (var k in opts.headers) {
                if (Object.prototype.hasOwnProperty.call(opts.headers, k)) {
                    headers[k] = opts.headers[k];
                }
            }
        }
        var body;
        if ('body' in opts && opts.body != null) {
            // JSON conventions: a string body is treated as pre-stringified
            // JSON; an object body is stringified. Anything else (FormData,
            // Blob) is out of scope for fetchJson — use fetch directly.
            body = typeof opts.body === 'string' ? opts.body : JSON.stringify(opts.body);
            if (!headers['Content-Type']) headers['Content-Type'] = 'application/json';
        }
        withCsrf(method, headers);
        return fetch(url, {
            method: method,
            credentials: 'same-origin',
            headers: headers,
            body: body
        }).then(function (resp) {
            return resp.text().then(function (text) {
                var data = null;
                if (text) {
                    try { data = JSON.parse(text); } catch (parseErr) { data = null; }
                }
                return { ok: resp.ok, status: resp.status, data: data };
            });
        });
    }

    /**
     * openFeedChannel(opts) — the ONE live-feed transport every runtime
     * shares. A feed rides the page's single KISS stream (window.SemitexaUi.sse,
     * owned by event-runtime.js): it is subscribed, re-viewed and detached
     * through HUG by its route name, and its frames arrive on KISS. There is no
     * per-feed EventSource. A page without a KISS session pulls instead.
     *
     * opts:
     *   feed                 (string) the feed's route name — its OPTIONS
     *                        contract `name`
     *   params               (object | () => object) the feed's query params
     *   dataEvent            (string) typed SSE event name for data frames
     *   errorEvent           (string) typed SSE event name for error frames
     *   onData(envelope)     parsed data-frame envelope
     *   onError(envelope)    parsed error-frame envelope
     *   onStreamId(id)       optional; the client-minted subscription id
     *   onStatus(s)          optional; 'live' once attached
     *   onPull()             optional; called once when the page has no KISS
     *                        session (asynchronously) or its KISS stream fails
     *                        before it ever connects: the caller fetches the
     *                        feed's plain JSON GET instead
     *
     * Returns { mode(): 'shared'|'pull'|'closed', view(params), close() }.
     * view() sends the complete new view; close() hard-unsubscribes so the
     * server reaps the subscription while the page's stream stays open.
     */
    function openFeedChannel(opts) {
        var closed = false;
        var sub = null;
        var mode = 'pull';

        function params() {
            return typeof opts.params === 'function' ? (opts.params() || {}) : (opts.params || {});
        }

        function onSharedFrame(frame) {
            if (closed || !frame || typeof frame._type !== 'string') return;
            if (frame._type === opts.dataEvent) {
                opts.onData(frame);
            } else if (frame._type === opts.errorEvent) {
                opts.onError(frame);
            }
        }

        var mgr = window.SemitexaUi && window.SemitexaUi.sse;
        if (mgr && typeof mgr.subscribe === 'function' && typeof opts.feed === 'string' && opts.feed !== '') {
            var handle = mgr.subscribe({ feed: opts.feed }, params(), onSharedFrame, function () {
                // The page's KISS stream never connected: pull instead.
                sub = null;
                if (closed) return;
                mode = 'pull';
                if (opts.onPull) opts.onPull();
            });
            if (handle && !handle.degraded) {
                sub = handle;
                mode = 'shared';
                if (opts.onStreamId) opts.onStreamId(handle.subscriptionId);
                if (opts.onStatus) opts.onStatus('live');
            }
        }
        if (mode === 'pull' && opts.onPull) {
            // Asynchronous, so the caller holds the returned handle first.
            Promise.resolve().then(function () { if (!closed) opts.onPull(); });
        }

        return {
            mode: function () { return closed ? 'closed' : mode; },
            view: function (next) {
                if (closed || !sub || typeof sub.view !== 'function') return false;
                sub.view(next || params());
                return true;
            },
            close: function () {
                closed = true;
                mode = 'closed';
                if (sub) {
                    try { sub.unsubscribe(); } catch (e) { /* noop */ }
                    sub = null;
                }
            }
        };
    }

    function onReady(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    // Declarative form behaviours — the platform grammar's replacement for
    // inline handler sprinkles (CSP-hostile and un-greppable):
    //   <form data-ui-inert-form>            JS-managed; native submit never
    //                                        navigates (was onsubmit="event.
    //                                        preventDefault()" per form)
    //   <form data-ui-confirm="Really?">     native submit gated behind a
    //                                        confirm() prompt (destructive
    //                                        actions)
    // One capture-phase listener serves every current and future form.
    document.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (!form || !form.matches) return;
        if (form.matches('form[data-ui-inert-form]')) {
            ev.preventDefault();
            return;
        }
        if (form.matches('form[data-ui-confirm]')) {
            var message = form.getAttribute('data-ui-confirm') || 'Are you sure?';
            if (!window.confirm(message)) {
                ev.preventDefault();
                ev.stopImmediatePropagation();
            }
        }
    }, true);

    ns.core = {
        version: 1,
        esc: esc,
        readCsrfToken: readCsrfToken,
        withCsrf: withCsrf,
        fetchJson: fetchJson,
        openFeedChannel: openFeedChannel,
        onReady: onReady
    };
})();

/*
 * ESM surface — this file loads as <script type="module"> (importable via
 * the server-generated import map as 'platform-ui/core'). The IIFE above
 * still populates window.SemitexaUi.core for the classic runtimes during
 * the runtime-by-runtime migration; both surfaces expose the SAME objects.
 */
const __core = window.SemitexaUi.core;
export const esc = __core.esc;
export const readCsrfToken = __core.readCsrfToken;
export const withCsrf = __core.withCsrf;
export const fetchJson = __core.fetchJson;
export const openFeedChannel = __core.openFeedChannel;
export const onReady = __core.onReady;
