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
 *   .mount(selector, def)  the ONE element lifecycle: def.connect(el, ctx)
 *                          for every matching element now and later, and
 *                          teardown (ctx.signal aborts, api.destroy()) when
 *                          it leaves the page — one MutationObserver for all
 *   .mintId(prefix, bytes) prefix + random hex (event / correlation ids)
 *   .envelope(o)           the canonical HUG UI event envelope
 *   .hug(body, opts)       POST a body to HUG (CSRF, JSON); { ok, status, data }
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
     *   patchEvent           (string) optional typed event name for patch
     *                        frames (a keyed change to the last data frame)
     *   onPatch(frame)       optional; parsed patch frame
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
            } else if (opts.patchEvent && frame._type === opts.patchEvent && opts.onPatch) {
                opts.onPatch(frame);
            } else if (frame._type === opts.errorEvent) {
                opts.onError(frame);
            }
        }

        var mgr = window.SemitexaUi && window.SemitexaUi.sse;
        if (mgr && typeof mgr.subscribe === 'function' && typeof opts.feed === 'string' && opts.feed !== '') {
            var handle = mgr.subscribe({ feed: opts.feed, patches: !!(opts.patchEvent && opts.onPatch) }, params(), onSharedFrame, function () {
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
        var tooLarge = oversizedFileInput(form);
        if (tooLarge) {
            // A novalidate form, or one a script submits, skips the browser's
            // own check: the request would still be refused by the server.
            ev.preventDefault();
            ev.stopImmediatePropagation();
            tooLarge.reportValidity();
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

    // Files the server cannot take. Swoole refuses a request over its limit
    // before any handler runs, and the browser reports only a dropped
    // connection, so a photo from a phone would simply never arrive. The page
    // carries the limit (<meta name="semitexa-request-max">, written by the
    // SSR head); choosing files that would not fit, or submitting them, makes
    // the file input invalid with a message saying so. Upload fields
    // ([data-ui-upload]) send each file on its own and check their own limit.
    var REQUEST_FRAMING_BYTES = 16384;

    function requestFileCeiling() {
        var meta = document.querySelector('meta[name="semitexa-request-max"]');
        var limit = meta ? parseInt(meta.getAttribute('content') || '', 10) : 0;
        return limit > REQUEST_FRAMING_BYTES ? limit - REQUEST_FRAMING_BYTES : 0;
    }

    function humanBytes(bytes) {
        return bytes >= 1048576 ? (Math.round((bytes / 1048576) * 10) / 10) + ' MB' : Math.ceil(bytes / 1024) + ' KB';
    }

    function sentFileInputs(scope) {
        return Array.prototype.filter.call(
            scope.querySelectorAll ? scope.querySelectorAll('input[type="file"]') : [],
            function (input) { return !input.disabled && !input.hasAttribute('data-ui-upload'); }
        );
    }

    /** The file input to blame when the scope's files would not fit; null when they fit. */
    function checkFileSizes(inputs) {
        var ceiling = requestFileCeiling();
        var total = 0;
        inputs.forEach(function (input) {
            Array.prototype.forEach.call(input.files || [], function (file) { total += file.size; });
        });
        var blamed = null;
        inputs.forEach(function (input) {
            var chosen = input.files && input.files.length > 0;
            var over = ceiling > 0 && total > ceiling && chosen;
            if (over) {
                input.setCustomValidity('Too large to send: ' + humanBytes(total) + ' chosen, and this site takes at most ' + humanBytes(ceiling) + ' at once.');
                input.__sxTooLarge = true;
                if (!blamed) blamed = input;
            } else if (input.__sxTooLarge) {
                // Only the message this check set: an app's own stays.
                input.setCustomValidity('');
                input.__sxTooLarge = false;
            }
        });
        return blamed;
    }

    function oversizedFileInput(form) {
        return checkFileSizes(sentFileInputs(form));
    }

    document.addEventListener('change', function (ev) {
        var input = ev.target;
        if (!input || !input.matches || !input.matches('input[type="file"]') || input.hasAttribute('data-ui-upload')) return;
        var blamed = checkFileSizes(input.form ? sentFileInputs(input.form) : [input]);
        if (blamed) blamed.reportValidity();
    }, true);

    // ---- One element lifecycle --------------------------------------------
    //
    // Every runtime used to carry its own boot: a readyState check, its own
    // MutationObserver, its own list of "re-scan now" events (which differed
    // per runtime, so a deferred block booted a grid but not a calendar), and
    // its own idea of teardown (or none). mount() is the one place for all of
    // it. An element a morph merely MOVED is still in the document when the
    // observer runs, so it is neither torn down nor connected twice.
    var MOUNTS = [];          // [{ selector, def }]
    var MOUNT_KEY = '__sxMounts';
    var observing = false;

    function connectOne(el, entry) {
        var store = el[MOUNT_KEY] || (el[MOUNT_KEY] = {});
        if (store[entry.selector]) return;
        var controller = new AbortController();
        var ctx = {
            root: el,
            signal: controller.signal,
            on: function (target, type, handler, o) {
                var opts = { signal: controller.signal };
                if (o) for (var k in o) if (Object.prototype.hasOwnProperty.call(o, k)) opts[k] = o[k];
                target.addEventListener(type, handler, opts);
            }
        };
        var slot = { controller: controller, api: null };
        store[entry.selector] = slot;
        try {
            slot.api = entry.def.connect(el, ctx) || null;
        } catch (e) {
            delete store[entry.selector];
            controller.abort();
            if (typeof console !== 'undefined' && console.error) console.error('[semitexa-ui] mount "' + entry.selector + '" failed', e);
        }
    }

    function disconnectOne(el, selector) {
        var store = el[MOUNT_KEY];
        var slot = store && store[selector];
        if (!slot) return;
        delete store[selector];
        try { if (slot.api && typeof slot.api.destroy === 'function') slot.api.destroy(); } catch (e) { /* ignore */ }
        try { slot.controller.abort(); } catch (e) { /* ignore */ }
    }

    function eachMatch(node, selector, fn) {
        if (!node || node.nodeType !== 1) return;
        if (node.matches && node.matches(selector)) fn(node);
        if (node.querySelectorAll) {
            var found = node.querySelectorAll(selector);
            for (var i = 0; i < found.length; i++) fn(found[i]);
        }
    }

    function observe() {
        if (observing || typeof MutationObserver === 'undefined' || !document.documentElement) return;
        observing = true;
        new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                var m = mutations[i];
                for (var r = 0; r < m.removedNodes.length; r++) {
                    var gone = m.removedNodes[r];
                    if (gone.nodeType !== 1 || gone.isConnected) continue; // moved, not removed
                    for (var a = 0; a < MOUNTS.length; a++) {
                        var sel = MOUNTS[a].selector;
                        eachMatch(gone, sel, function (el) { disconnectOne(el, sel); });
                    }
                }
                for (var n = 0; n < m.addedNodes.length; n++) {
                    var added = m.addedNodes[n];
                    if (added.nodeType !== 1 || !added.isConnected) continue;
                    for (var b = 0; b < MOUNTS.length; b++) {
                        var entry = MOUNTS[b];
                        eachMatch(added, entry.selector, function (el) { connectOne(el, entry); });
                    }
                }
            }
        }).observe(document.documentElement, { childList: true, subtree: true });
    }

    function mount(selector, def) {
        if (typeof selector !== 'string' || !def || typeof def.connect !== 'function') {
            throw new TypeError('mount(selector, { connect(el, ctx) }) expected');
        }
        var entry = { selector: selector, def: def };
        MOUNTS.push(entry);
        onReady(function () {
            observe();
            eachMatch(document.documentElement, selector, function (el) { connectOne(el, entry); });
        });
        return {
            /** Connect whatever matches inside root now (an explicit re-scan). */
            scan: function (root) { eachMatch(root || document.documentElement, selector, function (el) { connectOne(el, entry); }); }
        };
    }

    // ---- One HUG client -----------------------------------------------------

    var HUG_PATH = '/__semitexa_hug';

    function mintId(prefix, bytes) {
        var buf = new Uint8Array(bytes || 16);
        (window.crypto || window.msCrypto).getRandomValues(buf);
        var hex = '';
        for (var i = 0; i < buf.length; i++) hex += (buf[i] < 16 ? '0' : '') + buf[i].toString(16);
        return (prefix || '') + hex;
    }

    /** The canonical UI event envelope HUG decodes (UiEventEnvelope). */
    function envelope(o) {
        return {
            schemaVersion: 1,
            eventId: o.eventId || mintId('ui_evt_', 16),
            correlationId: o.correlationId || mintId('ui_cor_', 16),
            semanticEvent: o.semanticEvent,
            signedContext: o.signedContext,
            timestamp: new Date().toISOString(),
            payload: o.payload || {}
        };
    }

    /** POST one body to HUG. opts.keepalive for fire-and-forget on unload. */
    function hug(body, opts) {
        opts = opts || {};
        return fetch(HUG_PATH, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: opts.keepalive === true,
            headers: withCsrf('POST', { 'Content-Type': 'application/json', 'Accept': 'application/json' }),
            body: typeof body === 'string' ? body : JSON.stringify(body)
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

    ns.core = {
        version: 2,
        esc: esc,
        readCsrfToken: readCsrfToken,
        withCsrf: withCsrf,
        fetchJson: fetchJson,
        openFeedChannel: openFeedChannel,
        onReady: onReady,
        mount: mount,
        mintId: mintId,
        envelope: envelope,
        hug: hug,
        HUG_PATH: HUG_PATH
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
export const mount = __core.mount;
export const mintId = __core.mintId;
export const envelope = __core.envelope;
export const hug = __core.hug;
export const HUG_PATH = __core.HUG_PATH;
