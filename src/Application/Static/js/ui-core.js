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

    function onReady(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    ns.core = {
        version: 1,
        esc: esc,
        readCsrfToken: readCsrfToken,
        withCsrf: withCsrf,
        fetchJson: fetchJson,
        onReady: onReady
    };
})();
