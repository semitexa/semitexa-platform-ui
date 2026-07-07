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
 *   .openFeedChannel(opts) the ONE live-feed transport: shared KISS
 *                          subscribe first, dedicated EventSource degrade
 *                          with stream-id adoption + backoff reconnect —
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
            if (typeof opts.body === 'string') {
                body = opts.body;
            } else {
                body = JSON.stringify(opts.body);
                if (!headers['Content-Type']) headers['Content-Type'] = 'application/json';
            }
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
     * shares. Prefers the page's single multiplexed KISS connection
     * (window.SemitexaUi.sse, owned by event-runtime.js) and degrades to a
     * dedicated held-open EventSource with server-minted stream-id adoption
     * and exponential-backoff reconnect. Before this existed, grid-runtime-v2
     * and form-collab-runtime each carried a verbatim copy of this dance.
     *
     * opts:
     *   url                  (string) the feed endpoint
     *   params               (object | () => object) query params; re-read on
     *                        every dedicated (re)connect so the CURRENT view
     *                        rides each reopen
     *   dataEvent            (string) typed SSE event name for data frames
     *   errorEvent           (string) typed SSE event name for error frames
     *   onData(envelope)     parsed data-frame envelope (shared or dedicated)
     *   onError(envelope)    parsed error-frame envelope; null when the frame
     *                        body was unparseable
     *   onBadFrame(err)      optional; unparseable DATA frame (dedicated path)
     *   onStreamId(id, mode) optional; command-addressing id adoption —
     *                        'shared' = client-minted subscription id (ungated
     *                        immediately), 'dedicated' = server-minted
     *                        ui.stream.id frame
     *   onConnecting()       optional; a dedicated (re)connect is about to
     *                        open (the previous stream id is dead)
     *   onStatus(s)          optional; 'live' (shared attach) | 'reconnecting'
     *   permanentPullDegrade (bool) when true and the dedicated stream errors
     *                        before EVER delivering a frame on ANY connection,
     *                        the endpoint cannot stream here — call
     *                        onPermanentDegrade() once and stop. A reconnect
     *                        that errors mid-backoff (server restart) is NOT
     *                        that case and stays on the backoff path.
     *   onPermanentDegrade() optional; see above
     *   maxBackoffMs         optional; reconnect backoff ceiling (default 30s)
     *
     * Returns { mode(): 'shared'|'dedicated'|'closed', close() }. close()
     * hard-unsubscribes the shared subscription (so the server reaps its
     * record) and/or closes the dedicated stream + pending reconnect timer.
     */
    function openFeedChannel(opts) {
        var closed = false;
        var sub = null;             // shared-connection subscription handle
        var source = null;          // dedicated EventSource (degrade path)
        var reconnectTimer = null;
        var attempts = 0;
        var gotFrame = false;       // per-connection
        var everStreamed = false;   // across all connections
        var mode = 'dedicated';

        function params() {
            return typeof opts.params === 'function' ? (opts.params() || {}) : (opts.params || {});
        }

        function closeSource() {
            if (source) {
                try { source.close(); } catch (e) { /* noop */ }
                source = null;
            }
        }

        function onSharedFrame(frame) {
            if (!frame || typeof frame._type !== 'string') return;
            if (frame._type === opts.dataEvent) {
                everStreamed = true;
                attempts = 0;
                opts.onData(frame);
            } else if (frame._type === opts.errorEvent) {
                everStreamed = true;
                opts.onError(frame);
            }
        }

        function openStream() {
            if (closed) return;
            if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null; }
            closeSource();
            gotFrame = false;
            if (opts.onConnecting) opts.onConnecting();
            var p = params();
            var pairs = [];
            Object.keys(p).forEach(function (key) {
                if (p[key] === '' || p[key] == null) return;
                pairs.push(encodeURIComponent(key) + '=' + encodeURIComponent(p[key]));
            });
            var url = pairs.length === 0
                ? opts.url
                : opts.url + (opts.url.indexOf('?') === -1 ? '?' : '&') + pairs.join('&');
            try {
                source = new EventSource(url, { withCredentials: true });
            } catch (e) {
                scheduleReconnect();
                return;
            }

            // Adopt the server-minted id — commands stay gated until it lands.
            source.addEventListener('ui.stream.id', function (ev) {
                try {
                    var d = JSON.parse(ev.data);
                    if (d && typeof d.stream_id === 'string' && d.stream_id && opts.onStreamId) {
                        opts.onStreamId(d.stream_id, 'dedicated');
                    }
                } catch (e) { /* malformed id frame → commands stay gated */ }
            });

            source.addEventListener(opts.dataEvent, function (ev) {
                gotFrame = true;
                everStreamed = true;
                attempts = 0;
                var envelope;
                try {
                    envelope = JSON.parse(ev.data);
                } catch (e) {
                    if (opts.onBadFrame) opts.onBadFrame(e);
                    return;
                }
                opts.onData(envelope);
            });

            source.addEventListener(opts.errorEvent, function (ev) {
                gotFrame = true;
                everStreamed = true;
                var envelope;
                try { envelope = JSON.parse(ev.data); } catch (e) { envelope = null; }
                opts.onError(envelope);
            });

            source.onerror = function () {
                closeSource();
                if (opts.permanentPullDegrade && !gotFrame && !everStreamed) {
                    closed = true;
                    mode = 'closed';
                    if (opts.onPermanentDegrade) opts.onPermanentDegrade();
                    return;
                }
                if (opts.onStatus) opts.onStatus('reconnecting');
                scheduleReconnect();
            };
        }

        function scheduleReconnect() {
            if (closed || reconnectTimer !== null) return;
            var delay = Math.min(opts.maxBackoffMs || 30000, 1000 * Math.pow(2, attempts));
            attempts += 1;
            reconnectTimer = setTimeout(function () {
                reconnectTimer = null;
                openStream();
            }, delay);
        }

        // Prefer the ONE shared KISS connection (SSE transport unification);
        // it owns reconnect + resubscribe for every multiplexed feed.
        var mgr = window.SemitexaUi && window.SemitexaUi.sse;
        if (mgr && typeof mgr.subscribe === 'function') {
            var handle = mgr.subscribe({ url: opts.url }, params(), onSharedFrame);
            if (handle && !handle.degraded) {
                sub = handle;
                mode = 'shared';
                if (opts.onStreamId) opts.onStreamId(handle.subscriptionId, 'shared');
                if (opts.onStatus) opts.onStatus('live');
            }
        }
        if (mode !== 'shared') {
            openStream();
        }

        return {
            mode: function () { return closed ? 'closed' : mode; },
            close: function () {
                closed = true;
                mode = 'closed';
                if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null; }
                if (sub) {
                    // Hard unsubscribe so the server reaps this subscription's
                    // record even while the shared connection stays open.
                    try { sub.unsubscribe(); } catch (e) { /* noop */ }
                    sub = null;
                }
                closeSource();
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
