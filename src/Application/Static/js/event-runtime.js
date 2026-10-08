/**
 * Semitexa Platform UI — frontend event runtime (capture-only).
 *
 * Scope (this slice):
 *   - Scan the DOM for <script type="application/json" data-ui-event-manifest>
 *     blocks emitted by the server next to each component root.
 *   - Parse manifests and attach delegated DOM listeners on document for
 *     every distinct native event name declared across all manifests.
 *   - On fire: walk up from event.target to the nearest
 *     [data-ui-component-instance-id], find the part element by its
 *     `ui="<part-name>"` attribute, build a structured payload, and
 *     publish it locally — `document` CustomEvent + onCapture() callbacks.
 *
 * NOT in scope:
 *   - No HTTP transport. The runtime never makes a network request.
 *   - No signature verification. The signed `ctx` blob is treated as
 *     opaque and passed through to consumers untouched, matching the
 *     shape the future backend dispatcher will receive.
 *   - No DOM mutation. The runtime never modifies attributes, never
 *     adds nodes, never preventDefaults a captured event.
 *   - No backend dispatch. No UiInteractionDispatcher, no state patches,
 *     no validation, no SSE.
 *
 * Public API (window.SemitexaUi):
 *   .version          string, e.g. '1.0'
 *   .manifests()      returns a snapshot of parsed manifests
 *   .scan(root?)      rescan the document (or a subtree) for new manifests
 *   .onCapture(fn)    register a capture listener; returns unsubscribe fn
 *
 * DOM events also dispatched:
 *   `semitexa:ui-event:captured` on document, detail = captured payload
 *
 * Captured payload shape:
 *   {
 *     component:       string,    // canonical name, e.g. "platform.field"
 *     instanceId:      string,    // per-render id, e.g. "uci_<hex>"
 *     part:            string,    // logical part name, e.g. "input"
 *     event:           string,    // semantic event name, e.g. "change"
 *     updates:         ?string,   // bound value path, e.g. "value"
 *     ctx:             string,    // opaque signed-context blob (sc1.…)
 *     value:           any,       // current part value, when extractable
 *     originalEvent:   Event,     // the native DOM event
 *     manifestVersion: int        // payload.v from the manifest
 *   }
 *
 * Idempotent: re-evaluating this script is a no-op.
 */
// ES module: CSRF plumbing arrives through the import map
// ('platform-ui/core' -> fingerprinted URL); the import graph guarantees
// the core is initialized before this executes. This file itself is
// importable as 'platform-ui/events' (named exports appended at the end).
import { withCsrf, mount, envelope, hug } from 'platform-ui/core';

(function () {
    'use strict';

    // Extend-don't-replace: ui-core.js (and other runtimes) share the
    // window.SemitexaUi namespace. Only bail when THIS runtime already ran —
    // `version` is the marker this file's export sets.
    if (window.SemitexaUi && window.SemitexaUi.version) {
        return;
    }

    var MANIFEST_VERSION = 1;
    var SCANNED_FLAG = '__semitexaUiScanned';


    var captureListeners = [];
    var parsedManifests = []; // {scriptEl, payload}
    var delegatedNativeEvents = {};

    function parseManifestScript(scriptEl) {
        try {
            var text = scriptEl.textContent || scriptEl.innerText || '';
            if (text === '') {
                return null;
            }
            var payload = JSON.parse(text);
            if (!payload || typeof payload !== 'object') {
                return null;
            }
            if (payload.v !== MANIFEST_VERSION) {
                // Refuse unknown versions — runtime must opt in to format changes.
                if (typeof console !== 'undefined' && console.warn) {
                    console.warn(
                        '[semitexa-ui] manifest version mismatch, ignored',
                        { expected: MANIFEST_VERSION, received: payload.v }
                    );
                }
                return null;
            }
            if (!payload.i || typeof payload.i !== 'string') {
                return null;
            }
            if (!payload.c || typeof payload.c !== 'string') {
                return null;
            }
            if (!payload.events || !payload.events.length) {
                // No events declared — still a valid manifest, just inert.
                payload.events = payload.events || [];
            }
            return payload;
        } catch (err) {
            if (typeof console !== 'undefined' && console.warn) {
                console.warn('[semitexa-ui] failed to parse manifest', err);
            }
            return null;
        }
    }

    function ensureDelegation(nativeEvent) {
        if (delegatedNativeEvents[nativeEvent]) {
            return;
        }
        delegatedNativeEvents[nativeEvent] = true;
        document.addEventListener(nativeEvent, function (ev) {
            handleNativeEvent(nativeEvent, ev);
        }, true);
    }

    function findInstanceRoot(target) {
        if (!target || !target.closest) {
            return null;
        }
        return target.closest('[data-ui-component-instance-id]');
    }

    function findManifestForInstance(instanceId) {
        for (var i = 0; i < parsedManifests.length; i++) {
            if (parsedManifests[i].payload.i === instanceId) {
                return parsedManifests[i].payload;
            }
        }
        return null;
    }

    function findPartElement(rootEl, partName) {
        if (!rootEl || !partName) {
            return null;
        }
        // Canonical lookup: explicit `data-ui-part="<partName>"` injected
        // by the server-side `ui_part()` Twig helper. This decouples the
        // runtime from the primitive's `ui` alias, so a UiPart can be
        // named independently of the underlying primitive.
        var safe = partName.replace(/"/g, '\\"');
        var primary = rootEl.querySelector('[data-ui-part="' + safe + '"]');
        if (primary) {
            return primary;
        }
        // Back-compat: legacy templates that render the primitive directly
        // (e.g. via `primitive()` + `ui_part_props()`) still emit ui="…"
        // on the primitive root. Match by alias when no explicit marker
        // is present.
        return rootEl.querySelector('[ui="' + safe + '"]') || null;
    }

    function extractValue(partEl, originalEvent) {
        return readControlValue(partEl);
    }

    /**
     * The value of a form control, whatever kind it is:
     *   input / textarea / select      → its string value
     *   select[multiple]               → list of the selected values
     *   input[type=checkbox]           → true / false
     *   input[type=radio]              → its value when checked, else null
     *   a group (fieldset, div) holding checkboxes → list of the checked values
     *   a group holding radios         → the checked value, or null
     */
    function readControlValue(el) {
        if (!el || el.nodeType !== 1) {
            return null;
        }
        try {
            var tag = el.tagName;
            if (tag === 'INPUT') {
                if (el.type === 'checkbox') return !!el.checked;
                if (el.type === 'radio') return el.checked ? el.value : null;
                return el.value;
            }
            if (tag === 'SELECT') {
                if (!el.multiple) return el.value;
                var picked = [];
                for (var o = 0; o < el.options.length; o++) {
                    if (el.options[o].selected) picked.push(el.options[o].value);
                }
                return picked;
            }
            if (tag === 'TEXTAREA') return el.value;
            var boxes = el.querySelectorAll('input[type="checkbox"]');
            if (boxes.length > 0) {
                var checked = [];
                for (var b = 0; b < boxes.length; b++) {
                    if (boxes[b].checked) checked.push(boxes[b].value);
                }
                return checked;
            }
            var radios = el.querySelectorAll('input[type="radio"]');
            if (radios.length > 0) {
                for (var r = 0; r < radios.length; r++) {
                    if (radios[r].checked) return radios[r].value;
                }
                return null;
            }
            if ('value' in el) return el.value;
            return el.getAttribute ? el.getAttribute('value') : null;
        } catch (err) {
            return null;
        }
    }

    function notifyListeners(captured) {
        for (var i = 0; i < captureListeners.length; i++) {
            try {
                captureListeners[i](captured);
            } catch (err) {
                if (typeof console !== 'undefined' && console.error) {
                    console.error('[semitexa-ui] capture listener error', err);
                }
            }
        }
    }

    function handleNativeEvent(nativeEvent, ev) {
        var rootEl = findInstanceRoot(ev.target);
        if (!rootEl) {
            return;
        }
        var instanceId = rootEl.getAttribute('data-ui-component-instance-id');
        if (!instanceId) {
            return;
        }
        var manifest = findManifestForInstance(instanceId);
        if (!manifest) {
            return;
        }
        var events = manifest.events;
        for (var i = 0; i < events.length; i++) {
            var entry = events[i];
            if (entry.e !== nativeEvent) {
                continue;
            }
            var partEl = findPartElement(rootEl, entry.p);
            if (!partEl) {
                continue;
            }
            if (partEl !== ev.target && !partEl.contains(ev.target)) {
                continue;
            }

            // preventDefault for managed `<form>` submits ONLY. The
            // runtime is capture-only by default — input events,
            // change events, click events etc. all bubble through
            // untouched. A native form submit, however, would
            // navigate the browser away before the dispatcher could
            // respond; we hijack ONLY the submit-on-<form>-part case.
            // Other components with declared submit handlers on
            // non-<form> parts (none today) are intentionally NOT
            // affected — they would have to call preventDefault from
            // a capture listener themselves.
            if (nativeEvent === 'submit' && partEl.tagName === 'FORM') {
                try { ev.preventDefault(); } catch (preventErr) { /* ignore */ }
            }

            var captured = {
                component: manifest.c,
                instanceId: manifest.i,
                part: entry.p,
                event: entry.e,
                updates: entry.u || null,
                ctx: entry.ctx,
                value: extractValue(partEl, ev),
                originalEvent: ev,
                element: partEl,
                manifestVersion: manifest.v
            };

            // A form already submitting does not submit again (double click,
            // Enter held down): the first answer decides.
            if (nativeEvent === 'submit' && isSubmitInFlight(captured.instanceId)) {
                continue;
            }
            // The form lifecycle opens here; the server closes it with
            // ui-form:accepted / ui-form:rejected / ui-form:invalid.
            if (nativeEvent === 'submit') {
                var formRoot = instanceRoot(captured.instanceId);
                if (formRoot) formRoot.dispatchEvent(new CustomEvent('ui-form:submit', { bubbles: true }));
            }

            if (typeof console !== 'undefined' && console.debug) {
                console.debug(
                    '[semitexa-ui] captured',
                    captured.component + '#' + captured.instanceId,
                    captured.part + '.' + captured.event,
                    captured
                );
            }

            try {
                document.dispatchEvent(new CustomEvent('semitexa:ui-event:captured', {
                    detail: captured,
                    bubbles: false,
                    cancelable: false
                }));
            } catch (err) {
                if (typeof console !== 'undefined' && console.warn) {
                    console.warn('[semitexa-ui] CustomEvent dispatch failed', err);
                }
            }

            sendTimed(captured, entry, rootEl);
        }
    }

    function scanRoot(root) {
        if (!root || !root.querySelectorAll) {
            return 0;
        }
        var scripts = root.querySelectorAll(
            'script[type="application/json"][data-ui-event-manifest]'
        );
        var added = 0;
        for (var i = 0; i < scripts.length; i++) {
            var scriptEl = scripts[i];
            if (scriptEl[SCANNED_FLAG]) {
                continue;
            }
            scriptEl[SCANNED_FLAG] = true;

            var payload = parseManifestScript(scriptEl);
            if (!payload) {
                continue;
            }

            // A re-rendered instance (morph) brings a fresh manifest with fresh
            // signed contexts: it replaces the old one, which is also dropped
            // once its element has left the page.
            for (var k = parsedManifests.length - 1; k >= 0; k--) {
                var old = parsedManifests[k];
                if (old.payload.i === payload.i || (old.scriptEl && old.scriptEl.isConnected === false)) {
                    parsedManifests.splice(k, 1);
                }
            }
            parsedManifests.push({ scriptEl: scriptEl, payload: payload });
            added++;

            for (var j = 0; j < payload.events.length; j++) {
                ensureDelegation(payload.events[j].e);
            }
        }
        return added;
    }

    function scan(root) {
        if (root && root.nodeType === 1 && root.matches &&
            root.matches('script[type="application/json"][data-ui-event-manifest]')) {
            // Caller passed a single manifest script directly.
            return scanRoot(root.parentNode || document);
        }
        return scanRoot(root || document);
    }

    function manifests() {
        // Return a snapshot — callers should not be able to mutate internal state.
        var snapshot = [];
        for (var i = 0; i < parsedManifests.length; i++) {
            var entry = parsedManifests[i];
            snapshot.push({
                instanceId: entry.payload.i,
                component: entry.payload.c,
                events: entry.payload.events.slice(),
                manifestVersion: entry.payload.v
            });
        }
        return snapshot;
    }

    function onCapture(fn) {
        if (typeof fn !== 'function') {
            return function () {};
        }
        captureListeners.push(fn);
        return function unsubscribe() {
            var idx = captureListeners.indexOf(fn);
            if (idx >= 0) {
                captureListeners.splice(idx, 1);
            }
        };
    }

    // Late manifests (deferred components, navigation swaps, morphs) arrive
    // through the one element lifecycle (core.mount) instead of a private
    // MutationObserver; a manifest leaving the page is pruned on the next scan.
    function startObserver() {
        mount('script[type="application/json"][data-ui-event-manifest]', {
            connect: function (scriptEl) {
                // A late-arriving manifest (typical case: SSR-deferred component
                // delivered via the canonical KISS stream) means the initial-load
                // auto-attach skipped this page because parsedManifests was empty
                // at DOMContentLoaded; maybeAutoAttachTransport is idempotent.
                if (scanRoot(scriptEl.parentNode || document) > 0 && typeof maybeAutoAttachTransport === 'function') {
                    try {
                        maybeAutoAttachTransport();
                    } catch (e) {
                        if (typeof console !== 'undefined' && console.warn) {
                            console.warn('[semitexa-ui] late-manifest auto-attach failed', e);
                        }
                    }
                }
            }
        });
    }

    /**
     * Opt-in HTTP transport bridge.
     *
     * Until `transport.attach(...)` is called, the runtime makes ZERO
     * network requests. Once attached, the bridge subscribes to captured
     * events and POSTs `{ctx, payload}` to the configured endpoint.
     *
     * Wire-shape contract:
     *   - The body contains exactly two fields: `ctx` (the opaque signed
     *     blob) and `payload` (a small object with caller-controlled
     *     data, currently `{value}`). Nothing else.
     *   - The bridge MUST NOT serialize component, instance, part, event,
     *     handler, method, class, endpoint, url, route, action, or
     *     dispatcher fields. Routing identity is exclusively inside ctx.
     *   - The bridge does NOT decode or verify ctx — it treats the blob
     *     as opaque, exactly the way the dispatcher expects.
     *   - The bridge does NOT call preventDefault / stopPropagation on
     *     the underlying DOM event.
     *   - When the server response includes a `patches` array, the bridge
     *     applies them through a small SAFE applier (`setText`, `setValue`,
     *     `setAttribute` with a tight attribute allowlist). The applier
     *     never uses innerHTML, never evaluates strings, never accepts
     *     arbitrary CSS selectors, and only ever touches descendants of
     *     the component instance root identified by the signed claims.
     *   - Lifecycle is surfaced through CustomEvents on document:
     *       `semitexa:ui-event:dispatching`  (before fetch)
     *       `semitexa:ui-event:dispatched`   (on 2xx)
     *       `semitexa:ui-event:failed`       (on non-2xx or thrown)
     *       `semitexa:ui-patch:applied`      (one per successfully applied patch)
     *       `semitexa:ui-patch:failed`       (one per patch that could not apply)
     */
    var attachedTransports = [];

    /**
     * Generate a per-attempt dispatch id.
     *
     * Format: `ui_evt_<32 hex>`. The dispatcher accepts
     * `[A-Za-z0-9][A-Za-z0-9_-]{4,127}`; this format slots in well within
     * those bounds. 128 bits of randomness from
     * `crypto.getRandomValues` — sufficient to make accidental
     * collisions between captured events negligible within a single ctx
     * TTL window.
     *
     * Falls back to Math.random + Date.now if Web Crypto is unavailable
     * (e.g. very old browsers / non-secure contexts). The fallback is
     * NOT cryptographically random — it's only there so we still
     * generate a well-formed id; the replay guard treats dispatchIds as
     * opaque anyway.
     *
     * It is the envelope's `eventId` on HUG; the dispatcher maps
     * `eventId → dispatchId` 1:1 for replay protection.
     */
    function generateDispatchId() {
        return mintHexPrefixedId('ui_evt_', 16);
    }

    /**
     * Per-event correlation id for the canonical envelope. Free-form
     * tracing aid — never used for routing, replay, or security
     * decisions. Server-side this lands in dispatcher logs and in the
     * outbound canonical envelope's `correlationId` field, so clients
     * can correlate request/response without exposing the dispatchId.
     */
    function generateCorrelationId() {
        return mintHexPrefixedId('ui_cor_', 16);
    }

    function mintHexPrefixedId(prefix, byteCount) {
        var hex = '';
        try {
            var crypto = window.crypto || window.msCrypto;
            if (crypto && typeof crypto.getRandomValues === 'function') {
                var bytes = new Uint8Array(byteCount);
                crypto.getRandomValues(bytes);
                for (var i = 0; i < bytes.length; i++) {
                    var b = bytes[i].toString(16);
                    if (b.length < 2) b = '0' + b;
                    hex += b;
                }
                return prefix + hex;
            }
        } catch (e) {
            // fall through to non-crypto fallback
        }
        var rnd = (Math.random().toString(16) + '0000000000000000').slice(2, 18)
            + (Date.now().toString(16) + '0000000000000000').slice(0, 16);
        return prefix + rnd.slice(0, byteCount * 2);
    }

    /**
     * Derive the canonical envelope's `semanticEvent` from the captured
     * payload. The dispatcher uses `semanticEvent` for logging /
     * tracing only — handler identity comes exclusively from the signed
     * context. Format: `<component>.<event>` (e.g. `platform.form.submit`,
     * `platform.field.change`). Stable across releases so log greps
     * keep working.
     */
    function deriveSemanticEvent(captured) {
        var component = captured && typeof captured.component === 'string' ? captured.component : 'platform.ui';
        var event = captured && typeof captured.event === 'string' ? captured.event : 'event';
        return component + '.' + event;
    }

    /**
     * HUG — `POST /__semitexa_hug` (semitexa-ssr's `HugEventHandler`) — is
     * the one inbound door for every UI event; KISS (`/__semitexa_kiss`) is
     * the one stream back. The body is always the canonical envelope.
     */
    var DEFAULT_TRANSPORT_ENDPOINT = '/__semitexa_hug';

    // ---- Input timing (#[UiOn(debounce:, throttle:)]) ---------------------
    //
    // The manifest says WHEN to send (d = debounce ms, t = throttle ms); it
    // never changes what the server accepts. Debounce sends the LAST value
    // after a pause; throttle sends the first at once and the last at the end
    // of the window. A form submit first sends every pending debounce inside
    // it, so the server validates what the user actually typed.
    var PENDING_DEBOUNCE = {};   // key -> { timer, captured }
    var THROTTLES = {};          // key -> { last, timer, captured }

    function timingKey(captured) {
        return captured.instanceId + '|' + captured.part + '|' + captured.event;
    }

    function sendTimed(captured, entry, rootEl) {
        if (captured.event === 'submit') {
            flushPendingWithin(rootEl);
            // A submit is answered after everything the form already sent — its
            // fields' changes, its own flushed input (a re-render) — which would
            // otherwise land after the submit's verdict and overwrite it.
            if (inFlightWithin(rootEl)) {
                SUBMIT_WAITERS.push({ rootEl: rootEl, captured: captured, entry: entry });
                return;
            }
        }
        var key = timingKey(captured);
        var debounce = typeof entry.d === 'number' && entry.d > 0 ? entry.d : 0;
        var throttle = typeof entry.t === 'number' && entry.t > 0 ? entry.t : 0;
        if (debounce > 0) {
            var pending = PENDING_DEBOUNCE[key];
            if (pending) clearTimeout(pending.timer);
            PENDING_DEBOUNCE[key] = {
                captured: captured,
                timer: setTimeout(function () {
                    delete PENDING_DEBOUNCE[key];
                    notifyListeners(captured);
                }, debounce)
            };
            return;
        }
        if (throttle > 0) {
            var now = Date.now();
            var state = THROTTLES[key] || (THROTTLES[key] = { last: 0, timer: null, captured: null });
            if (now - state.last >= throttle) {
                state.last = now;
                notifyListeners(captured);
                return;
            }
            state.captured = captured;
            if (state.timer === null) {
                state.timer = setTimeout(function () {
                    state.timer = null;
                    state.last = Date.now();
                    var trailing = state.captured;
                    state.captured = null;
                    if (trailing) notifyListeners(trailing);
                }, throttle - (now - state.last));
            }
            return;
        }
        notifyListeners(captured);
    }

    function flushPendingWithin(rootEl) {
        Object.keys(PENDING_DEBOUNCE).forEach(function (key) {
            var pending = PENDING_DEBOUNCE[key];
            var el = pending.captured.element;
            if (!rootEl || !el || !rootEl.contains(el)) return;
            clearTimeout(pending.timer);
            delete PENDING_DEBOUNCE[key];
            notifyListeners(pending.captured);
        });
    }

    // ---- Loading states --------------------------------------------------
    //
    // While an action is in flight its component root carries aria-busy and
    // the part that fired it carries data-loading (style both in CSS). A
    // submitting form also turns its inputs readonly and its buttons
    // disabled, and a second submit is dropped. Anything else is declared in
    // markup with the `ui-loading` grammar (after Symfony UX):
    //
    //   ui-loading="show"                          hidden until loading
    //   ui-loading="addAttribute(disabled)"        while loading
    //   ui-loading="action(save)|addClass(is-dim)" only while part `save` acts
    //   ui-loading="delay(300)|show"               only if it takes > 300ms
    //   ui-loading="action(form.submit)|hide addClass(a b)"   several directives;
    //                                       action()/delay() bind the directive they lead
    //
    // Effects: show, hide, addClass(c …), removeClass(c …), addAttribute(a),
    // removeAttribute(a). Each is undone when the action's answer arrives.
    var IN_FLIGHT = {};          // instanceId -> { count, submits }
    var LOADING_EFFECTS = { show: 1, hide: 1, addClass: 1, removeClass: 1, addAttribute: 1, removeAttribute: 1 };
    var LOADING_ATTR_RE = /^(?!on)[a-z][a-z0-9-]*$/;
    var DEFAULT_LOADING_DELAY = 200;

    /** The one lookup of a component instance's root by its (safe) id. */
    function instanceRoot(instanceId) {
        if (typeof instanceId !== 'string' || !IDENTIFIER_RE.test(instanceId)) return null;
        return document.querySelector('[data-ui-component-instance-id="' + cssAttrEscape(instanceId) + '"]');
    }

    function isSubmitInFlight(instanceId) {
        var state = IN_FLIGHT[instanceId];
        if (state && state.submits > 0) return true;
        return SUBMIT_WAITERS.some(function (w) { return w.captured.instanceId === instanceId; });
    }

    var SUBMIT_WAITERS = [];     // submits held until their fields answered

    function inFlightWithin(rootEl) {
        if (!rootEl) return false;
        // A file still on its way (upload-runtime) is the field's value to be.
        if (rootEl.querySelector('[data-ui-uploading]')) return true;
        return Object.keys(IN_FLIGHT).some(function (id) {
            var el = instanceRoot(id);
            return el !== null && rootEl.contains(el);
        });
    }

    document.addEventListener('ui-upload:end', function () {
        if (SUBMIT_WAITERS.length > 0) releaseSubmitWaiters();
    });

    function releaseSubmitWaiters() {
        var waiting = SUBMIT_WAITERS;
        SUBMIT_WAITERS = [];
        waiting.forEach(function (w) {
            if (w.rootEl.isConnected) sendTimed(w.captured, w.entry, w.rootEl);
        });
    }

    /** `ui-loading` value → [{ scope: {part, event}|null, delay, effect, arg }]. */
    function parseLoading(spec, effects) {
        effects = effects || LOADING_EFFECTS;
        var out = [];
        var tokens = String(spec || '').match(/(?:[^\s()|]+(?:\([^)]*\))?\|?)+/g) || [];
        for (var t = 0; t < tokens.length; t++) {
            var pieces = tokens[t].split('|');
            var directive = { scope: null, delay: 0, effect: null, arg: '' };
            for (var p = 0; p < pieces.length; p++) {
                var m = /^([A-Za-z]+)(?:\(([^)]*)\))?$/.exec(pieces[p]);
                if (!m) continue;
                var name = m[1];
                var arg = m[2] === undefined ? null : m[2].trim();
                if (name === 'action' && arg) {
                    var dot = arg.indexOf('.');
                    directive.scope = dot === -1 ? { part: arg, event: null } : { part: arg.slice(0, dot), event: arg.slice(dot + 1) };
                } else if (name === 'delay') {
                    directive.delay = arg === null ? DEFAULT_LOADING_DELAY : Math.max(0, parseInt(arg, 10) || 0);
                } else if (effects[name]) {
                    directive.effect = name;
                    directive.arg = arg || '';
                }
            }
            if (directive.effect !== null) out.push(directive);
        }
        return out;
    }

    function applyLoadingEffect(el, directive) {
        var arg = directive.arg;
        var names = arg.split(/\s+/).filter(Boolean);
        switch (directive.effect) {
            case 'show':
                el.hidden = false;
                return function () { el.hidden = true; };
            case 'hide':
                var wasHidden = el.hidden;
                el.hidden = true;
                return function () { el.hidden = wasHidden; };
            case 'addClass':
                var added = names.filter(function (c) { return !el.classList.contains(c); });
                added.forEach(function (c) { el.classList.add(c); });
                return function () { added.forEach(function (c) { el.classList.remove(c); }); };
            case 'removeClass':
                var removed = names.filter(function (c) { return el.classList.contains(c); });
                removed.forEach(function (c) { el.classList.remove(c); });
                return function () { removed.forEach(function (c) { el.classList.add(c); }); };
            case 'addAttribute':
                if (!LOADING_ATTR_RE.test(arg) || el.hasAttribute(arg)) return null;
                el.setAttribute(arg, '');
                return function () { el.removeAttribute(arg); };
            case 'removeAttribute':
                if (!LOADING_ATTR_RE.test(arg) || !el.hasAttribute(arg)) return null;
                var previous = el.getAttribute(arg);
                el.removeAttribute(arg);
                return function () { el.setAttribute(arg, previous); };
        }
        return null;
    }

    function beginLoading(captured) {
        var token = { instanceId: captured.instanceId, undo: [], timers: [], submit: captured.event === 'submit' };
        var rootEl = instanceRoot(captured.instanceId);
        var state = IN_FLIGHT[captured.instanceId] || (IN_FLIGHT[captured.instanceId] = { count: 0, submits: 0 });
        state.count++;
        if (token.submit) state.submits++;
        if (!rootEl) return token;

        rootEl.setAttribute('aria-busy', 'true');
        var triggers = [captured.element];
        // A form submit's own button shows it too (the event's submitter).
        if (captured.originalEvent && captured.originalEvent.submitter) triggers.push(captured.originalEvent.submitter);
        triggers.forEach(function (trigger) {
            if (!trigger || !trigger.setAttribute || trigger.hasAttribute('data-loading')) return;
            trigger.setAttribute('data-loading', '');
            token.undo.push(function () { trigger.removeAttribute('data-loading'); });
        });

        if (token.submit) {
            var controls = rootEl.querySelectorAll('input, textarea, select, button');
            for (var c = 0; c < controls.length; c++) {
                (function (ctl) {
                    if (ctl.tagName === 'BUTTON' || ctl.type === 'submit' || ctl.tagName === 'SELECT') {
                        if (ctl.disabled) return;
                        ctl.disabled = true;
                        token.undo.push(function () { ctl.disabled = false; });
                    } else if (!ctl.readOnly) {
                        ctl.readOnly = true;
                        token.undo.push(function () { ctl.readOnly = false; });
                    }
                })(controls[c]);
            }
        }

        var declared = rootEl.matches('[ui-loading]') ? [rootEl] : [];
        var inside = rootEl.querySelectorAll('[ui-loading]');
        for (var d = 0; d < inside.length; d++) declared.push(inside[d]);
        declared.forEach(function (el) {
            parseLoading(el.getAttribute('ui-loading')).forEach(function (directive) {
                var scope = directive.scope;
                if (scope && (scope.part !== captured.part || (scope.event !== null && scope.event !== captured.event))) return;
                var run = function () {
                    var undo = applyLoadingEffect(el, directive);
                    if (undo) token.undo.push(undo);
                };
                if (directive.delay > 0) token.timers.push(setTimeout(run, directive.delay)); else run();
            });
        });
        token.rootEl = rootEl;
        return token;
    }

    function endLoading(token) {
        if (!token || token.ended) return;
        token.ended = true;
        token.timers.forEach(function (t) { clearTimeout(t); });
        for (var u = token.undo.length - 1; u >= 0; u--) {
            try { token.undo[u](); } catch (e) { /* a morphed-away element */ }
        }
        var state = IN_FLIGHT[token.instanceId];
        if (state) {
            state.count--;
            if (token.submit) state.submits--;
            if (state.count <= 0) {
                delete IN_FLIGHT[token.instanceId];
                var rootEl = instanceRoot(token.instanceId);
                if (rootEl) rootEl.removeAttribute('aria-busy');
            }
        }
        if (SUBMIT_WAITERS.length > 0) releaseSubmitWaiters();
    }

    // Optimistic UI — the answer an action expects, drawn the moment it fires,
    // declared in markup with the same grammar as ui-loading:
    //
    //   ui-optimistic="action(increment)|increment"     the count goes up now
    //   ui-optimistic="action(remove)|hide"              the row goes now
    //   ui-optimistic="action(star)|toggleAttribute(aria-pressed) addClass(is-on)"
    //
    // Effects: hide, text(v), increment(n), addClass(c …), removeClass(c …),
    // addAttribute(a), removeAttribute(a), toggleAttribute(a). The prediction
    // stands when the server accepts — its answer (a morph, a patch) then puts
    // the truth in place; when it refuses or cannot be reached, every effect is
    // undone and the visitor is told. The server stays the truth: nothing here
    // decides anything, it only draws early what the server is expected to say.
    var OPTIMISTIC_EFFECTS = { hide: 1, text: 1, increment: 1, addClass: 1, removeClass: 1, addAttribute: 1, removeAttribute: 1, toggleAttribute: 1 };

    function applyOptimisticEffect(el, directive) {
        var arg = directive.arg;
        switch (directive.effect) {
            case 'text':
                var previousText = el.textContent;
                el.textContent = arg;
                return function () { el.textContent = previousText; };
            case 'increment':
                var before = el.textContent;
                var current = parseFloat(String(before).replace(/[^0-9.+-]/g, ''));
                if (isNaN(current)) return null;
                var step = arg === '' ? 1 : parseFloat(arg);
                if (isNaN(step)) return null;
                el.textContent = String(current + step);
                return function () { el.textContent = before; };
            case 'toggleAttribute':
                if (!LOADING_ATTR_RE.test(arg)) return null;
                var had = el.hasAttribute(arg);
                var was = el.getAttribute(arg);
                if (had) el.removeAttribute(arg); else el.setAttribute(arg, '');
                return function () { if (had) el.setAttribute(arg, was); else el.removeAttribute(arg); };
        }
        return applyLoadingEffect(el, directive);
    }

    function beginOptimistic(captured) {
        var undo = [];
        var rootEl = instanceRoot(captured.instanceId);
        if (!rootEl) return undo;
        var declared = rootEl.matches('[ui-optimistic]') ? [rootEl] : [];
        var inside = rootEl.querySelectorAll('[ui-optimistic]');
        for (var d = 0; d < inside.length; d++) declared.push(inside[d]);
        declared.forEach(function (el) {
            parseLoading(el.getAttribute('ui-optimistic'), OPTIMISTIC_EFFECTS).forEach(function (directive) {
                var scope = directive.scope;
                if (scope && (scope.part !== captured.part || (scope.event !== null && scope.event !== captured.event))) return;
                var revert = applyOptimisticEffect(el, directive);
                if (revert) undo.push(revert);
            });
        });
        return undo;
    }

    /** The server refused or could not be reached: every prediction undone, and said so. */
    function rollBackOptimistic(undo, captured) {
        if (undo.length === 0) return;
        for (var u = undo.length - 1; u >= 0; u--) {
            try { undo[u](); } catch (e) { /* a morphed-away element */ }
        }
        var toast = window.SemitexaUi && window.SemitexaUi.toast;
        if (typeof toast === 'function') {
            toast('That did not go through. Nothing was changed.', { status: TOAST_STATUS.error || 'danger' });
        } else {
            emitTransportEvent('semitexa:ui-toast', { message: 'That did not go through. Nothing was changed.', level: 'error' });
        }
        emitTransportEvent('semitexa:ui-optimistic:rolled-back', { captured: captured });
    }

    function hideIdleLoadingElements(rootEl, instanceId) {
        if (IN_FLIGHT[instanceId]) return;
        var els = rootEl.querySelectorAll('[ui-loading]');
        for (var i = 0; i < els.length; i++) {
            if (parseLoading(els[i].getAttribute('ui-loading')).some(function (d) { return d.effect === 'show'; })) {
                els[i].hidden = true;
            }
        }
    }

    // `show` elements start hidden: they appear only while loading.
    mount('[ui-loading]', {
        connect: function (el) {
            if (parseLoading(el.getAttribute('ui-loading')).some(function (d) { return d.effect === 'show'; })) {
                el.hidden = true;
            }
        }
    });

    function attachTransport(options) {
        if (typeof options !== 'object' || options === null) {
            options = { endpoint: DEFAULT_TRANSPORT_ENDPOINT };
        }
        var endpoint = typeof options.endpoint === 'string' && options.endpoint !== ''
            ? options.endpoint
            : DEFAULT_TRANSPORT_ENDPOINT;

        if (typeof fetch !== 'function') {
            if (typeof console !== 'undefined' && console.warn) {
                console.warn('[semitexa-ui] transport.attach: fetch is not available; no network calls will fire.');
            }
            return function () {};
        }
        // Attaching twice is a no-op: a page that attaches by hand while the
        // runtime auto-attached would otherwise send every event twice (a
        // form's second submit then spends a consumed one-time token).
        for (var a = 0; a < attachedTransports.length; a++) {
            if (attachedTransports[a].endpoint === endpoint) return attachedTransports[a].detach;
        }

        var unsubscribe = onCapture(function (captured) {
            // Per-attempt id. Generated CLIENT-SIDE for every captured
            // event so the server replay guard can deduplicate retries
            // (network races, double-click). The signed `ctx` is
            // intentionally reusable within its TTL — only the
            // (ctx, dispatchId) pair has to be unique.
            var dispatchId = generateDispatchId();

            // Build the wire body. ctx + dispatchId + payload. The
            // server forbids any of `dispatchId`/`requestId`/`eventId`
            // *inside* payload, so we keep them strictly at top level.
            //
            // Cross-field validation snapshot: when the captured field
            // lives inside a [data-ui-form-aggregate="1"] root, walk
            // sibling fields with `data-ui-field-name` markers and
            // include their current scalar input values as
            // `payload.form.values`. The server treats the snapshot as
            // UX-feedback input only — never authoritative state.
            // Outside a form, no snapshot is collected and no `form`
            // key appears on the wire.
            var payloadObj = { value: captured.value };
            var formSnapshot = collectFormValuesSnapshot(captured);
            if (formSnapshot !== null) {
                payloadObj.form = { values: formSnapshot };
            }

            // The canonical envelope HUG decodes (`UiEventEnvelope`).
            var body;
            var correlationId = generateCorrelationId();
            var semanticEvent = deriveSemanticEvent(captured);
            try {
                body = JSON.stringify(envelope({
                    eventId: dispatchId,
                    correlationId: correlationId,
                    semanticEvent: semanticEvent,
                    signedContext: captured.ctx,
                    payload: payloadObj
                }));
            } catch (encErr) {
                emitTransportEvent('semitexa:ui-event:failed', {
                    captured: captured,
                    dispatchId: dispatchId,
                    error: encErr,
                    phase: 'encode'
                });
                return;
            }

            emitTransportEvent('semitexa:ui-event:dispatching', {
                captured: captured,
                dispatchId: dispatchId,
                correlationId: correlationId,
                endpoint: endpoint
            });

            var loading = beginLoading(captured);
            var optimistic = beginOptimistic(captured);

            fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: withCsrf('POST', { 'Content-Type': 'application/json' }),
                body: body
            }).then(function (resp) {
                return resp.text().then(function (text) {
                    // The answer to THIS action ends its loading state.
                    endLoading(loading);
                    var parsed = null;
                    try { parsed = text ? JSON.parse(text) : null; } catch (parseErr) {
                        parsed = null;
                    }
                    if (resp.ok) {
                        emitTransportEvent('semitexa:ui-event:dispatched', {
                            captured: captured,
                            dispatchId: dispatchId,
                            correlationId: correlationId,
                            status: resp.status,
                            response: parsed
                        });
                        applyResponsePatches(parsed, captured);
                        // Client-local form-level aggregate. Reads the
                        // validation state the server already returned
                        // for the field and, if the field lives inside
                        // a [data-ui-form-aggregate="1"] root, refreshes
                        // the form-status text + ui-state attribute via
                        // synthetic patches that go through the SAME
                        // safe applier — no new mutation path.
                        try {
                            updateFormAggregate(parsed, captured);
                        } catch (aggErr) {
                            if (typeof console !== 'undefined' && console.warn) {
                                console.warn('[semitexa-ui] form aggregate failed', aggErr);
                            }
                        }
                    } else {
                        rollBackOptimistic(optimistic, captured);
                        emitTransportEvent('semitexa:ui-event:failed', {
                            captured: captured,
                            dispatchId: dispatchId,
                            status: resp.status,
                            response: parsed,
                            phase: 'response'
                        });
                    }
                });
            }).catch(function (err) {
                endLoading(loading);
                rollBackOptimistic(optimistic, captured);
                emitTransportEvent('semitexa:ui-event:failed', {
                    captured: captured,
                    dispatchId: dispatchId,
                    error: err,
                    phase: 'network'
                });
            });
        });

        var entry = { endpoint: endpoint, unsubscribe: unsubscribe };
        entry.detach = function detach() {
            entry.unsubscribe();
            var idx = attachedTransports.indexOf(entry);
            if (idx >= 0) {
                attachedTransports.splice(idx, 1);
            }
        };
        attachedTransports.push(entry);
        return entry.detach;
    }

    function emitTransportEvent(name, detail) {
        try {
            document.dispatchEvent(new CustomEvent(name, {
                detail: detail,
                bubbles: false,
                cancelable: false
            }));
        } catch (err) {
            if (typeof console !== 'undefined' && console.warn) {
                console.warn('[semitexa-ui] transport CustomEvent dispatch failed', err);
            }
        }
    }

    /**
     * Safe response-patch applier.
     *
     * Server may include a `patches` array on a successful dispatch:
     *   { op, target: { instance, part?, name? }, value?, attribute? }
     *
     * The applier:
     *   - rejects anything that is not a plain object;
     *   - rejects ops outside the small allow-list;
     *   - finds the component root by data-ui-component-instance-id;
     *   - finds the patch target *inside* that root by data-ui-part /
     *     data-ui-patch-target (NEVER by an arbitrary selector);
     *   - parses server-rendered HTML only through an inert <template>,
     *     never `eval`s, never executes scripts;
     *   - emits semitexa:ui-patch:applied / :failed lifecycle events per
     *     patch — one failed patch never breaks the rest of the batch.
     */
    // The one UI effect vocabulary (UiResponsePatch). The same list arrives on
    // a HUG reply and as KISS `ui.patch` pushes. HTML ops carry server-rendered
    // markup (a component re-render or a handler's Twig fragment) and are
    // parsed through an inert <template>, so no script in it ever runs.
    var ALLOWED_PATCH_OPS = {
        setText: true, setValue: true, setAttribute: true,
        morph: true, replace: true, append: true, prepend: true,
        remove: true, focus: true, redirect: true, toast: true, dispatch: true,
        reset: true, open: true, close: true, url: true
    };
    var TOAST_STATUS = { info: 'info', success: 'success', warning: 'warning', error: 'danger' };
    var DISPATCH_EVENT_RE = /^[a-z][a-z0-9]*(?:[:.-][a-z0-9]+)*$/;
    var ALLOWED_PATCH_ATTRIBUTES = {
        'aria-invalid': true,
        'aria-describedby': true,
        'data-state': true,
        'ui-state': true
    };
    var IDENTIFIER_RE = /^[A-Za-z_][A-Za-z0-9_-]*$/;

    function applyResponsePatches(response, captured) {
        if (!response || typeof response !== 'object') return;
        var patches = response.patches;
        if (!isArray(patches) || patches.length === 0) return;

        for (var i = 0; i < patches.length; i++) {
            applyOnePatch(patches[i], captured, i);
        }
    }

    function isArray(v) {
        return Array.isArray ? Array.isArray(v) : Object.prototype.toString.call(v) === '[object Array]';
    }

    function failPatch(patch, captured, index, reason) {
        emitTransportEvent('semitexa:ui-patch:failed', {
            patch: patch,
            captured: captured,
            index: index,
            reason: reason
        });
    }

    function applyOnePatch(patch, captured, index) {
        if (!patch || typeof patch !== 'object') {
            return failPatch(patch, captured, index, 'patch_not_object');
        }
        var op = patch.op;
        if (typeof op !== 'string' || !ALLOWED_PATCH_OPS[op]) {
            return failPatch(patch, captured, index, 'invalid_op');
        }
        var target = patch.target;
        if (!target || typeof target !== 'object') {
            return failPatch(patch, captured, index, 'invalid_target');
        }
        var instanceId = target.instance;
        if (typeof instanceId !== 'string' || !IDENTIFIER_RE.test(instanceId)) {
            return failPatch(patch, captured, index, 'invalid_target_instance');
        }
        var rootEl = document.querySelector(
            '[data-ui-component-instance-id="' + cssAttrEscape(instanceId) + '"]'
        );
        if (!rootEl) {
            return failPatch(patch, captured, index, 'root_not_found');
        }
        // Defense in depth: the server already pins every patch to the signed
        // instance or to an instance it signed alongside (a form's fields). The
        // client accepts the instance that sent the event and anything rendered
        // INSIDE it — a form answering for its own fields — never a stranger.
        if (captured && captured.instanceId && captured.instanceId !== instanceId) {
            var senderEl = instanceRoot(captured.instanceId);
            if (!senderEl || !senderEl.contains(rootEl)) {
                return failPatch(patch, captured, index, 'target_instance_mismatch');
            }
        }

        // Page-level effects: they act from the instance, not on a part of it.
        if (op === 'redirect' || op === 'toast' || op === 'dispatch' || op === 'morph' || op === 'url') {
            if (!applyInstanceEffect(op, patch, rootEl)) {
                return failPatch(patch, captured, index, 'invalid_' + op);
            }
            emitTransportEvent('semitexa:ui-patch:applied', { patch: patch, captured: captured, index: index });
            return;
        }

        var el = resolveTargetElement(rootEl, target, patch, captured, index);
        if (!el) return; // failPatch already emitted by resolveTargetElement

        switch (op) {
            case 'replace':
            case 'append':
            case 'prepend':
                var fragment = parseHtml(patch.value);
                if (fragment === null) {
                    return failPatch(patch, captured, index, 'invalid_html');
                }
                if (op === 'replace') {
                    el.replaceWith(fragment);
                    // A replaced event manifest (a form re-armed after a
                    // submit) must serve the very next event.
                    scan(document);
                } else if (op === 'append') {
                    el.appendChild(fragment);
                } else {
                    el.insertBefore(fragment, el.firstChild);
                }
                break;
            case 'remove':
                el.remove();
                break;
            case 'focus':
                if (typeof el.focus === 'function') el.focus();
                break;
            case 'reset':
                if (typeof el.reset !== 'function') {
                    return failPatch(patch, captured, index, 'target_not_a_form');
                }
                el.reset();
                break;
            case 'open':
            case 'close':
                // The instance itself is not an overlay: it means the overlay
                // the instance sits in (close the modal around a saved form).
                var overlay = el === rootEl && !overlayApi(el) ? closestOverlay(el.parentElement) : el;
                if (!overlay || !toggleOverlay(overlay, op === 'open')) {
                    return failPatch(patch, captured, index, 'target_not_an_overlay');
                }
                break;
            case 'setText':
                el.textContent = patch.value == null ? '' : String(patch.value);
                break;
            case 'setValue':
                if (!('value' in el)) {
                    return failPatch(patch, captured, index, 'target_has_no_value');
                }
                try { el.value = patch.value == null ? '' : String(patch.value); }
                catch (e) { return failPatch(patch, captured, index, 'set_value_failed'); }
                break;
            case 'setAttribute':
                var attr = patch.attribute;
                if (typeof attr !== 'string' || !ALLOWED_PATCH_ATTRIBUTES[attr]) {
                    return failPatch(patch, captured, index, 'invalid_attribute');
                }
                if (patch.value == null) {
                    el.removeAttribute(attr);
                } else {
                    el.setAttribute(attr, String(patch.value));
                }
                break;
            default:
                return failPatch(patch, captured, index, 'invalid_op');
        }

        emitTransportEvent('semitexa:ui-patch:applied', {
            patch: patch,
            captured: captured,
            index: index
        });
    }

    // ---- URL state (url effect) ------------------------------------------
    // A component's #[UiUrl] props changed: set / drop those query parameters
    // of the page's own address. On a shell page the navigation layer records
    // it (so Back is a page move it understands); elsewhere a Back to an entry
    // this pushed reloads, and the server restores the props from the URL.
    var URL_KEY_RE = /^[A-Za-z_][A-Za-z0-9_-]{0,63}$/;
    var urlStatePushed = false;

    function applyUrlState(params, push) {
        if (!params || typeof params !== 'object') return false;
        var url = new URL(window.location.href);
        var keys = Object.keys(params);
        if (keys.length === 0) return false;
        for (var k = 0; k < keys.length; k++) {
            var value = params[keys[k]];
            if (!URL_KEY_RE.test(keys[k]) || (value !== null && typeof value !== 'string')) return false;
            if (value === null) url.searchParams.delete(keys[k]); else url.searchParams.set(keys[k], value);
        }
        var target = url.pathname + url.search + url.hash;
        if (target === window.location.pathname + window.location.search + window.location.hash) return true;
        var nav = window.SemitexaNavigation;
        if (nav && typeof nav.recordUrl === 'function' && nav.isShellPage()) {
            nav.recordUrl(target, { replace: !push });
            return true;
        }
        if (push) {
            window.history.pushState({ semitexaUrlState: true }, '', target);
            if (!urlStatePushed) {
                urlStatePushed = true;
                window.addEventListener('popstate', function () { window.location.reload(); });
            }
        } else {
            window.history.replaceState(window.history.state, '', target);
        }
        return true;
    }

    // ---- Overlays (open / close effects) ---------------------------------
    // An overlay is an element carrying a togglable behavior (modal, offcanvas
    // — their open()/close() keep scroll-lock, transitions and events), else a
    // native <dialog>.
    var OVERLAY_ALIASES = ['modal', 'offcanvas'];

    function overlayApi(el) {
        var behaviors = window.SemitexaUi && window.SemitexaUi.behaviors;
        if (behaviors && typeof behaviors.instance === 'function') {
            for (var a = 0; a < OVERLAY_ALIASES.length; a++) {
                var inst = behaviors.instance(el, OVERLAY_ALIASES[a]);
                if (inst && inst.api && typeof inst.api.open === 'function' && typeof inst.api.close === 'function') return inst.api;
            }
        }
        if (el.tagName === 'DIALOG' && typeof el.close === 'function') {
            return {
                open: function () { if (!el.open) el.showModal(); },
                close: function () { if (el.open) el.close(); }
            };
        }
        return null;
    }

    function closestOverlay(el) {
        for (var node = el; node && node.nodeType === 1; node = node.parentElement) {
            if (overlayApi(node)) return node;
        }
        return null;
    }

    function toggleOverlay(el, open) {
        var overlay = overlayApi(el);
        if (!overlay) return false;
        if (open) overlay.open(); else overlay.close();
        return true;
    }

    /** Server-rendered HTML → a fragment, through an inert <template>. */
    function parseHtml(html) {
        if (typeof html !== 'string' || typeof document.createElement !== 'function') return null;
        var tpl = document.createElement('template');
        tpl.innerHTML = html;
        return tpl.content;
    }

    function applyInstanceEffect(op, patch, rootEl) {
        var args = patch.args && typeof patch.args === 'object' ? patch.args : {};
        switch (op) {
            case 'morph':
                var fragment = parseHtml(patch.value);
                var next = fragment ? fragment.firstElementChild : null;
                if (!next) return false;
                morphElement(rootEl, next);
                // Runtime-owned state the server HTML does not carry: `show`
                // loading elements are hidden again unless an action is still
                // in flight on this instance.
                hideIdleLoadingElements(rootEl, patch.target.instance);
                // Pick up the re-rendered manifest now, not on the observer's
                // next tick: the very next click must use the new contexts.
                scan(document);
                return true;
            case 'redirect':
                var path = patch.value;
                if (typeof path !== 'string' || path.charAt(0) !== '/' || path.charAt(1) === '/') return false;
                if (args.replace === true) window.location.replace(path); else window.location.assign(path);
                return true;
            case 'toast':
                if (typeof patch.value !== 'string' || patch.value === '') return false;
                var toast = window.SemitexaUi && window.SemitexaUi.toast;
                var opts = { status: TOAST_STATUS[args.level] || 'info' };
                if (typeof args.title === 'string') opts.title = args.title;
                if (typeof toast === 'function') {
                    toast(patch.value, opts);
                } else {
                    emitTransportEvent('semitexa:ui-toast', { message: patch.value, level: args.level || 'info', title: opts.title });
                }
                return true;
            case 'url':
                return applyUrlState(args.params, args.history === 'push');
            case 'dispatch':
                if (typeof patch.value !== 'string' || !DISPATCH_EVENT_RE.test(patch.value)) return false;
                rootEl.dispatchEvent(new CustomEvent(patch.value, { bubbles: true, detail: args.detail || {} }));
                return true;
        }
        return false;
    }

    /**
     * Morph `from` into `to` in place: attributes synced, children matched by
     * id or data-ui-part (else by position), so focus, caret and the value a
     * user is typing survive a re-render of the component around them.
     */
    function morphElement(from, to) {
        if (from.nodeType !== to.nodeType || from.nodeName !== to.nodeName) {
            from.replaceWith(to.cloneNode(true));
            return;
        }
        if (from.nodeType === 3 || from.nodeType === 8) {
            if (from.nodeValue !== to.nodeValue) from.nodeValue = to.nodeValue;
            return;
        }
        if (from.nodeType !== 1) return;
        if (from.nodeName === 'SCRIPT') {
            // A script (an event manifest) is swapped whole, so it is a new
            // node the runtime scans — never edited in place.
            from.replaceWith(to.cloneNode(true));
            return;
        }
        syncAttributes(from, to);
        if ('value' in from && (from.nodeName === 'INPUT' || from.nodeName === 'TEXTAREA' || from.nodeName === 'SELECT')) {
            if (from !== document.activeElement && from.value !== to.value) from.value = to.value;
            if (from.nodeName === 'INPUT' && from.checked !== to.checked) from.checked = to.checked;
        }
        morphChildren(from, to);
    }

    // State the browser owns, not the server HTML: whether a <dialog> or
    // <details> is open, and an overlay behavior's `sx-open` class. A re-render
    // of a component around an open modal must not shut it.
    var RUNTIME_OPEN_NODES = { DIALOG: true, DETAILS: true };

    function syncAttributes(from, to) {
        var i;
        var keepOpen = RUNTIME_OPEN_NODES[from.nodeName] === true;
        var wasShown = from.classList && from.classList.contains('sx-open');
        for (i = from.attributes.length - 1; i >= 0; i--) {
            var name = from.attributes[i].name;
            if (keepOpen && name === 'open') continue;
            if (!to.hasAttribute(name)) from.removeAttribute(name);
        }
        for (i = 0; i < to.attributes.length; i++) {
            var attr = to.attributes[i];
            if (keepOpen && attr.name === 'open') continue;
            if (from.getAttribute(attr.name) !== attr.value) from.setAttribute(attr.name, attr.value);
        }
        if (wasShown) from.classList.add('sx-open');
    }

    function morphKey(node) {
        if (!node || node.nodeType !== 1) return null;
        return node.getAttribute('id') ? '#' + node.getAttribute('id')
            : node.getAttribute('data-ui-part') ? 'part:' + node.getAttribute('data-ui-part')
            : null;
    }

    function morphChildren(from, to) {
        var keyed = {};
        var child;
        for (child = from.firstChild; child; child = child.nextSibling) {
            var key = morphKey(child);
            if (key !== null) keyed[key] = child;
        }
        var cursor = from.firstChild;
        var nextNew;
        for (var neu = to.firstChild; neu; neu = nextNew) {
            nextNew = neu.nextSibling;
            var neuKey = morphKey(neu);
            var match = neuKey !== null && keyed[neuKey] ? keyed[neuKey] : null;
            if (match === null && cursor && morphKey(cursor) === null
                && cursor.nodeType === neu.nodeType && cursor.nodeName === neu.nodeName) {
                match = cursor;
            }
            if (match !== null) {
                if (match !== cursor) from.insertBefore(match, cursor);
                else cursor = cursor.nextSibling;
                if (neuKey !== null) delete keyed[neuKey];
                morphElement(match, neu);
            } else {
                from.insertBefore(neu.cloneNode(true), cursor);
            }
        }
        while (cursor) {
            var rest = cursor.nextSibling;
            from.removeChild(cursor);
            cursor = rest;
        }
    }

    function resolveTargetElement(rootEl, target, patch, captured, index) {
        var part = target.part;
        var name = target.name;
        if (part != null) {
            if (typeof part !== 'string' || !IDENTIFIER_RE.test(part)) {
                failPatch(patch, captured, index, 'invalid_target_part');
                return null;
            }
            var partEl = rootEl.querySelector(
                '[data-ui-part="' + cssAttrEscape(part) + '"]'
            );
            if (!partEl) {
                failPatch(patch, captured, index, 'target_not_found');
                return null;
            }
            return partEl;
        }
        if (name != null) {
            if (typeof name !== 'string' || !IDENTIFIER_RE.test(name)) {
                failPatch(patch, captured, index, 'invalid_target_name');
                return null;
            }
            // The instance's own target first: a form and the fields nested
            // in it can carry the same name (every event manifest does).
            var named = rootEl.querySelectorAll(
                '[data-ui-patch-target="' + cssAttrEscape(name) + '"]'
            );
            var namedEl = named[0] || null;
            for (var n = 0; n < named.length; n++) {
                if (named[n].closest('[data-ui-component-instance-id]') === rootEl) { namedEl = named[n]; break; }
            }
            if (!namedEl) {
                failPatch(patch, captured, index, 'target_not_found');
                return null;
            }
            return namedEl;
        }
        return rootEl;
    }

    function cssAttrEscape(value) {
        // value is already constrained to /^[A-Za-z_][A-Za-z0-9_-]*$/, so
        // there are no special CSS characters to escape. Defensive
        // double-quote escape only.
        return String(value).replace(/"/g, '\\"');
    }

    /**
     * Cross-field validation snapshot collector.
     *
     * When the captured field lives inside a
     * [data-ui-form-aggregate="1"] root, walk every descendant field
     * that exposes a safe `data-ui-field-name` marker and pull its
     * current input value. Returns a plain object
     * `{<fieldName>: <scalarValue>}` ready to be embedded as
     * `payload.form.values`, or `null` when the field is not inside a
     * form (so the wire body stays unchanged for standalone fields).
     *
     * Hard constraints — must hold every time:
     *
     *   - Keys MUST match the safe-identifier shape
     *     `[A-Za-z_][A-Za-z0-9_-]*`. We re-validate at the client
     *     even though the template already filters, so a hostile or
     *     hand-injected `data-ui-field-name` attribute does NOT
     *     leak onto the wire. Same shape the server's
     *     UiFormPayloadSnapshot enforces.
     *   - Values are read off the field's `[data-ui-part="input"]`
     *     element through its `.value` property — same primitive
     *     surface the capture path already reads. No DOM traversal
     *     beyond the closest input part, no `innerText`, no
     *     `dataset` mining.
     *   - We collect ONLY values. Never rule specs, never config,
     *     never component / part / event identity, never selectors,
     *     never anything else that could be interpreted as routing.
     *   - The snapshot is scoped to the enclosing form root; fields
     *     outside that root are ignored. No cross-form bleed-through.
     *
     * The runtime never *evaluates* a rule against the collected
     * snapshot. Validation runs server-side; the snapshot is sent so
     * cross-field rules can produce a coherent UX message as the
     * user types.
     */
    var FIELD_NAME_SAFE_RE = /^[A-Za-z_][A-Za-z0-9_-]*$/;

    function collectFormValuesSnapshot(captured) {
        if (!captured || typeof captured.instanceId !== 'string') {
            return null;
        }
        var instanceEl = document.querySelector(
            '[data-ui-component-instance-id="' + cssAttrEscape(captured.instanceId) + '"]'
        );
        if (!instanceEl) {
            return null;
        }
        // The captured instance can be EITHER a field (resolve the
        // enclosing form-aggregate root by walking up from its
        // parent) OR the form root itself (when the dispatch is a
        // form.submit). Both produce the same downstream behaviour:
        // walk every descendant carrying a safe data-ui-field-name.
        var formRoot;
        if (instanceEl.matches && instanceEl.matches(
            '[data-ui-form-aggregate="1"][data-ui-component-instance-id]'
        )) {
            formRoot = instanceEl;
        } else if (instanceEl.parentNode && instanceEl.parentNode.closest) {
            formRoot = instanceEl.parentNode.closest(
                '[data-ui-form-aggregate="1"][data-ui-component-instance-id]'
            );
        } else {
            formRoot = null;
        }
        if (!formRoot) {
            return null;
        }

        var snapshot = {};
        var fields = formRoot.querySelectorAll('[data-ui-field-name]');
        for (var i = 0; i < fields.length; i++) {
            var fieldEl = fields[i];
            var name = fieldEl.getAttribute('data-ui-field-name');
            if (typeof name !== 'string' || !FIELD_NAME_SAFE_RE.test(name)) {
                continue;
            }
            // The field's input part — any control kind (readControlValue).
            var inputEl = fieldEl.querySelector('[data-ui-part="input"]');
            if (!inputEl) {
                continue;
            }
            var rawValue = readControlValue(inputEl);
            if (rawValue === null || rawValue === undefined) {
                snapshot[name] = null;
                continue;
            }
            // Scalars, or a flat list of strings (multi-select, checkbox
            // group) — never an object.
            if (Array.isArray(rawValue)) {
                snapshot[name] = rawValue.filter(function (v) { return typeof v === 'string'; });
                continue;
            }
            if (typeof rawValue !== 'string' && typeof rawValue !== 'number' &&
                typeof rawValue !== 'boolean'
            ) {
                continue;
            }
            snapshot[name] = rawValue;
        }
        return snapshot;
    }

    /**
     * Client-local form-level aggregation.
     *
     * Records per-field validation state returned by the server and
     * derives a single status line + a ui-state attribute on the
     * enclosing form root. The form root is the nearest ancestor
     * matching [data-ui-form-aggregate="1"][data-ui-component-instance-id]
     * — typically the FormComponent shell. The walker only ascends
     * within the DOM; it never crosses iframes / shadow roots / forms
     * the field is not actually inside.
     *
     * Field state shape (per form, per field key):
     *   { state: 'valid' | 'invalid', message: string | null }
     *
     * Aggregate (computed on every update, never persisted):
     *   knownCount      — number of distinct fields we have observed
     *   invalidCount    — number of fields whose latest state is 'invalid'
     *   validCount      — number of fields whose latest state is 'valid'
     *   aggregateState  — 'invalid' if any invalid, else 'valid' when at
     *                     least one field is known, else 'pending'
     *
     * The applier is the existing `applyOnePatch` — we synthesize patch
     * objects targeting the form's instance id, then feed them in with a
     * pseudo-captured envelope (same trick as the SSE bridge). Nothing
     * new lands on the DOM mutation engine; the form layer cannot do
     * anything the field layer couldn't already do.
     *
     * State is keyed by form instance id and is purely in-memory; a
     * page reload starts fresh. There is intentionally no broadcast,
     * no transport, and no persistence — the aggregate is a derived
     * view of dispatch responses the page has already seen.
     */
    var FORM_AGGREGATE_ATTR = 'data-ui-form-aggregate';
    var FIELD_NAME_ATTR = 'data-ui-field-name';
    /** form instance id → { fields: { fieldKey → state }, lastAt: number } */
    var FORM_AGGREGATE_STATE = {};

    function updateFormAggregate(response, captured) {
        if (!response || typeof response !== 'object') return;
        var debug = response.debug;
        if (!debug || typeof debug !== 'object') return;
        var validation = debug.validation;
        if (!validation || typeof validation !== 'object') return;
        var state = validation.state;
        if (state !== 'valid' && state !== 'invalid') return;

        var fieldInstance = (captured && captured.instanceId) ? captured.instanceId : null;
        if (typeof fieldInstance !== 'string' || !IDENTIFIER_RE.test(fieldInstance)) return;

        var fieldRoot = document.querySelector(
            '[data-ui-component-instance-id="' + cssAttrEscape(fieldInstance) + '"]'
        );
        if (!fieldRoot || !fieldRoot.closest) return;

        var formRoot = fieldRoot.parentNode && fieldRoot.parentNode.closest
            ? fieldRoot.parentNode.closest('[' + FORM_AGGREGATE_ATTR + '="1"][data-ui-component-instance-id]')
            : null;
        if (!formRoot) return;

        var formInstance = formRoot.getAttribute('data-ui-component-instance-id');
        if (typeof formInstance !== 'string' || !IDENTIFIER_RE.test(formInstance)) return;

        var fieldName = fieldRoot.getAttribute(FIELD_NAME_ATTR);
        if (typeof fieldName === 'string' && !IDENTIFIER_RE.test(fieldName)) {
            fieldName = null;
        }
        // Fall back to instance id as the field key so anonymous fields
        // still aggregate distinctly. The key is internal to the
        // runtime; it is never exposed in patches.
        var fieldKey = (fieldName && fieldName !== '') ? fieldName : fieldInstance;

        var bucket = FORM_AGGREGATE_STATE[formInstance];
        if (!bucket) {
            bucket = { fields: {}, lastAt: 0 };
            FORM_AGGREGATE_STATE[formInstance] = bucket;
        }

        var message = null;
        if (typeof validation.message === 'string') {
            message = validation.message;
        }
        bucket.fields[fieldKey] = { state: state, message: message };
        bucket.lastAt = (typeof Date !== 'undefined' && Date.now) ? Date.now() : 0;

        var summary = computeFormAggregate(bucket);
        applyAggregatePatches(formInstance, summary);

        try {
            document.dispatchEvent(new CustomEvent('semitexa:ui-form:aggregate', {
                detail: {
                    formInstance: formInstance,
                    fieldKey: fieldKey,
                    fieldState: bucket.fields[fieldKey],
                    summary: summary
                },
                bubbles: false,
                cancelable: false
            }));
        } catch (err) {
            // CustomEvent constructor failure (very old browsers) is a
            // non-fatal observability issue — the DOM patches above
            // already ran.
        }
    }

    function computeFormAggregate(bucket) {
        var invalidCount = 0;
        var validCount = 0;
        var knownCount = 0;
        var keys = Object.keys(bucket.fields);
        for (var i = 0; i < keys.length; i++) {
            knownCount++;
            var s = bucket.fields[keys[i]];
            if (s && s.state === 'invalid') {
                invalidCount++;
            } else if (s && s.state === 'valid') {
                validCount++;
            }
        }
        var aggregateState;
        if (invalidCount > 0) {
            aggregateState = 'invalid';
        } else if (knownCount > 0) {
            aggregateState = 'valid';
        } else {
            aggregateState = 'pending';
        }
        return {
            knownCount: knownCount,
            invalidCount: invalidCount,
            validCount: validCount,
            aggregateState: aggregateState,
            message: composeAggregateMessage(invalidCount, validCount, knownCount, aggregateState)
        };
    }

    function composeAggregateMessage(invalidCount, validCount, knownCount, aggregateState) {
        if (knownCount === 0) {
            return 'No fields validated yet.';
        }
        if (aggregateState === 'invalid') {
            if (invalidCount === 1) {
                return '1 field needs attention.';
            }
            return invalidCount + ' fields need attention.';
        }
        // valid
        if (knownCount === 1) {
            return '1 field validated — looks good.';
        }
        return 'All ' + knownCount + ' validated fields look good.';
    }

    function applyAggregatePatches(formInstance, summary) {
        var patches = [
            {
                op: 'setText',
                target: { instance: formInstance, name: 'form-status' },
                value: summary.message
            },
            {
                op: 'setAttribute',
                target: { instance: formInstance },
                attribute: 'ui-state',
                value: summary.aggregateState
            }
        ];
        var pseudoCaptured = { instanceId: formInstance, source: 'form-aggregate' };
        for (var i = 0; i < patches.length; i++) {
            applyOnePatch(patches[i], pseudoCaptured, i);
        }
    }

    function formAggregateSnapshot(formInstance) {
        if (typeof formInstance === 'string') {
            var bucket = FORM_AGGREGATE_STATE[formInstance];
            if (!bucket) {
                return null;
            }
            return {
                formInstance: formInstance,
                fields: shallowCloneFields(bucket.fields),
                summary: computeFormAggregate(bucket)
            };
        }
        var out = {};
        var keys = Object.keys(FORM_AGGREGATE_STATE);
        for (var i = 0; i < keys.length; i++) {
            var fInstance = keys[i];
            out[fInstance] = {
                formInstance: fInstance,
                fields: shallowCloneFields(FORM_AGGREGATE_STATE[fInstance].fields),
                summary: computeFormAggregate(FORM_AGGREGATE_STATE[fInstance])
            };
        }
        return out;
    }

    function formAggregateReset(formInstance) {
        if (typeof formInstance === 'string') {
            delete FORM_AGGREGATE_STATE[formInstance];
            return;
        }
        FORM_AGGREGATE_STATE = {};
    }

    function shallowCloneFields(fields) {
        var out = {};
        var keys = Object.keys(fields);
        for (var i = 0; i < keys.length; i++) {
            out[keys[i]] = { state: fields[keys[i]].state, message: fields[keys[i]].message };
        }
        return out;
    }

    /**
     * Opt-in Server-Sent Events bridge.
     *
     * Until `sse.attach({url})` is called, the runtime opens NO
     * EventSource. The bridge subscribes to a server-side channel that
     * was authorised via a signed token (the URL must already include
     * the token; the bridge treats it as opaque).
     *
     * Message contract:
     *   - The server emits events named `ui.patch`. The frame body is
     *     a JSON object `{v, patches, messageId?, publishedAt?}`. The
     *     bridge refuses unknown schema versions.
     *   - The bridge feeds the `patches` array into the SAME
     *     applyResponsePatches path used by POST /__semitexa_hug
     *     responses. There is no second DOM mutation engine.
     *   - The bridge also listens for `connected` and `close` events
     *     so consumers can correlate UI state with stream lifecycle.
     *
     * Lifecycle CustomEvents on `document`:
     *   semitexa:ui-sse:connected     (after `connected` event;
     *                                  detail = {detail, url})
     *   semitexa:ui-sse:message       (every `ui.patch` event;
     *                                  detail = {message, url})
     *   semitexa:ui-sse:patch-applied (one per applied patch in the
     *                                  message; detail = {patch, index})
     *   semitexa:ui-sse:patch-failed  (one per failed patch;
     *                                  detail = {patch, index, reason})
     *   semitexa:ui-sse:close         (on server `close` event;
     *                                  detail = {detail, url})
     *   semitexa:ui-sse:error         (transport error;
     *                                  detail = {phase, error?, url})
     */
    var ATTACHED_SSE_CONNECTIONS = [];
    var SSE_MESSAGE_VERSION = 1;
    // url -> last `connected` timestamp (ms). A second `connected` for the
    // same url means the stream re-established after a drop (native
    // EventSource reconnect, or an explicit visibility revive) — used to
    // emit `semitexa:ui-sse:reconnected` so page consumers (e.g. the grid)
    // can re-sync state that may have been published while the socket was
    // down. Keyed by url so two distinct streams do not cross-trigger.
    var SSE_LAST_CONNECTED_AT = {};
    var KISS_PATH = '/__semitexa_kiss';
    // The last frame id each stream got (`k<connection>.<n>`, numbered by the
    // server's replay transport), by url. A NEW EventSource for the same url (a
    // revived tab, a reopened stream) carries it as `last_event_id`, so the
    // server replays what the dying connection never delivered; the browser's
    // own reconnect sends it as the Last-Event-ID header by itself.
    var SSE_LAST_REPLAY_ID = {};
    var SSE_REPLAY_ID_RE = /^k[0-9a-f]{6}\.\d+$/;

    function withReplayId(url) {
        var last = SSE_LAST_REPLAY_ID[url];
        if (typeof last !== 'string' || last === '') {
            return url;
        }
        return url + (url.indexOf('?') === -1 ? '?' : '&') + 'last_event_id=' + encodeURIComponent(last);
    }

    function attachSse(options) {
        if (typeof options !== 'object' || options === null) {
            options = {};
        }
        var url = typeof options.url === 'string' ? options.url : '';
        if (url === '') {
            if (typeof console !== 'undefined' && console.warn) {
                console.warn('[semitexa-ui] sse.attach: missing options.url.');
            }
            return function () {};
        }
        if (typeof EventSource !== 'function') {
            if (typeof console !== 'undefined' && console.warn) {
                console.warn('[semitexa-ui] sse.attach: EventSource is not available.');
            }
            emitTransportEvent('semitexa:ui-sse:error', {
                phase: 'unsupported',
                url: url
            });
            return function () {};
        }

        // Same-URL dedupe — opening a second EventSource to the same
        // URL would duplicate every typed-message handler. Phase 3
        // Part 3 allows callers to attach idempotently (the page-level
        // helper may run twice during HMR / partial re-renders).
        for (var existing = 0; existing < ATTACHED_SSE_CONNECTIONS.length; existing++) {
            if (ATTACHED_SSE_CONNECTIONS[existing].url === url) {
                var existingEntry = ATTACHED_SSE_CONNECTIONS[existing];
                return function detachExisting() {
                    try { existingEntry.source.close(); } catch (e) { /* ignore */ }
                    var idx = ATTACHED_SSE_CONNECTIONS.indexOf(existingEntry);
                    if (idx >= 0) {
                        ATTACHED_SSE_CONNECTIONS.splice(idx, 1);
                    }
                };
            }
        }

        var source;
        try {
            source = new EventSource(withReplayId(url), { withCredentials: false });
            source.__semitexaUrl = url;
        } catch (err) {
            emitTransportEvent('semitexa:ui-sse:error', {
                phase: 'construct',
                error: err,
                url: url
            });
            return function () {};
        }

        source.addEventListener('connected', function (ev) {
            var parsed = parseSseFrame(ev);
            emitTransportEvent('semitexa:ui-sse:connected', {
                detail: parsed,
                url: url
            });
            // Reconnect detection: the FIRST `connected` for this url is the
            // initial open; every later one is a new connection, and the server
            // dropped the old one's feed subscriptions with it. Signal consumers
            // to re-subscribe. (This used to require two seconds since the
            // previous `connected`, so a stream that dropped and came back
            // quickly kept its feeds unsubscribed.)
            var nowTs = (typeof Date !== 'undefined' && Date.now) ? Date.now() : 1;
            var prevTs = SSE_LAST_CONNECTED_AT[url] || 0;
            SSE_LAST_CONNECTED_AT[url] = nowTs || 1;
            if (prevTs !== 0) {
                emitTransportEvent('semitexa:ui-sse:reconnected', {
                    url: url,
                    sincePreviousMs: nowTs - prevTs
                });
            }
        });

        // The server no longer holds the frame this reconnect named: what was
        // missed is lost, and consumers re-sync from fresh snapshots (feeds do
        // on the `reconnected` signal that follows).
        source.addEventListener('ui.stream.reset', function (ev) {
            emitTransportEvent('semitexa:ui-sse:reset', {
                detail: parseSseFrame(ev),
                url: url
            });
        });

        source.addEventListener('ui.patch', function (ev) {
            var parsed = parseSseFrame(ev);
            if (parsed === null) {
                emitTransportEvent('semitexa:ui-sse:error', {
                    phase: 'parse',
                    url: url
                });
                return;
            }
            // Shape-detect: canonical typed message from the kiss stream
            // carries `_type: 'ui.patch'` + a single `patch` body and
            // wraps it in a `componentInstanceId` envelope. A legacy
            // patch-array shape `{v, patches[]}` is still tolerated for
            // safety; we route by shape, not by URL.
            if (parsed._type === 'ui.patch') {
                emitTransportEvent('semitexa:ui-sse:message', {
                    message: parsed,
                    url: url
                });
                applyCanonicalUiPatch(parsed);
                return;
            }
            if (parsed.v !== SSE_MESSAGE_VERSION) {
                emitTransportEvent('semitexa:ui-sse:error', {
                    phase: 'version',
                    received: parsed.v,
                    expected: SSE_MESSAGE_VERSION,
                    url: url
                });
                return;
            }
            emitTransportEvent('semitexa:ui-sse:message', {
                message: parsed,
                url: url
            });
            applySsePatches(parsed.patches);
        });

        // Canonical typed `ui.componentState` — whole-state snapshot
        // for one component instance. No DOM consumer ships in this
        // slice (grid migration is Phase 4); we surface a safe
        // CustomEvent so future consumers can subscribe without
        // re-parsing the SSE frame.
        source.addEventListener('ui.componentState', function (ev) {
            var parsed = parseSseFrame(ev);
            if (parsed === null || parsed._type !== 'ui.componentState') {
                emitTransportEvent('semitexa:ui-sse:error', {
                    phase: 'parse',
                    url: url
                });
                return;
            }
            emitTransportEvent('semitexa:ui-sse:component-state', {
                message: parsed,
                url: url
            });
        });

        // Canonical typed `ui.error` — operator-safe error surface
        // delivered over the canonical SSE channel. The frame body
        // already contains only `reason` + `message` + optional
        // `correlationId` (the framework's UiErrorMessage value object
        // enforces the no-FQCN / no-trace contract at construction
        // time). We surface a CustomEvent without touching the DOM.
        source.addEventListener('ui.error', function (ev) {
            var parsed = parseSseFrame(ev);
            if (parsed === null || parsed._type !== 'ui.error') {
                emitTransportEvent('semitexa:ui-sse:error', {
                    phase: 'parse',
                    url: url
                });
                return;
            }
            emitTransportEvent('semitexa:ui-sse:error-message', {
                message: parsed,
                url: url
            });
        });

        source.addEventListener('close', function (ev) {
            var parsed = parseSseFrame(ev);
            // Deterministic teardown. SSR's AsyncResourceSseServer
            // emits `event: close` once it has flushed the drain
            // queue; if we do not call `source.close()` here, the
            // browser's EventSource would treat the server-initiated
            // shutdown as a transient error and silently reconnect,
            // which defeats the whole point of drain mode. Idempotent
            // on `live` streams that never receive a server-side
            // close.
            try { source.close(); } catch (closeErr) { /* ignore */ }
            var idx = ATTACHED_SSE_CONNECTIONS.indexOf(entry);
            if (idx >= 0) {
                ATTACHED_SSE_CONNECTIONS.splice(idx, 1);
            }
            // Announce only once the connection is gone: a listener that
            // reopens the same URL (drain mode, answers that arrived while
            // this drain was open) would otherwise be de-duplicated against
            // the dying connection — and every later answer would wait.
            emitTransportEvent('semitexa:ui-sse:close', {
                detail: parsed,
                url: url
            });
        });

        // SSE transport unification · Phase 3 — multiplex demux. The shared KISS
        // connection now also carries feed frames (collection/document) for the
        // page's subscriptions, each tagged with its `streaming_id`. Route each to
        // the registered subscription callback; an unrecognised id falls through to
        // the generic `semitexa:ui-sse:frame` re-emit, so nothing regresses.
        SSE_DATA_FRAME_TYPE_LIST.forEach(function (type) {
            source.addEventListener(type, function (ev) {
                var parsed = parseSseFrame(ev);
                if (parsed === null) {
                    return;
                }
                if (!routeSubscriptionFrame(parsed)) {
                    emitTransportEvent('semitexa:ui-sse:frame', { message: parsed, url: url });
                }
            });
        });

        // Forward default (unnamed) SSE frames so a consumer can subscribe to
        // the SINGLE shared stream instead of opening its own EventSource. The
        // deferred-SSR runtime (semitexa-twig.js) carries its deferred_block /
        // deferred_component / done frames in the JSON body with no `event:`
        // line, so they land here on `message` rather than on the typed
        // listeners above (ui.patch / ui.componentState / ui.error / connected
        // / close), which are dispatched by name and never reach this handler.
        source.onmessage = function (ev) {
            var parsed = parseSseFrame(ev);
            if (parsed === null) {
                return;
            }
            emitTransportEvent('semitexa:ui-sse:frame', {
                message: parsed,
                url: url
            });
        };

        source.onerror = function (err) {
            emitTransportEvent('semitexa:ui-sse:error', {
                phase: 'transport',
                error: err,
                url: url
            });
            // A KISS stream that fails before it EVER connected cannot stream on
            // this page: stop retrying and let every feed fall back to pull. A
            // stream that drops after connecting stays on the browser's own
            // reconnect, and the `reconnected` signal re-subscribes.
            if (url.indexOf(KISS_PATH) !== -1 && !SSE_LAST_CONNECTED_AT[url]) {
                try { source.close(); } catch (closeErr) { /* ignore */ }
                var failedIdx = ATTACHED_SSE_CONNECTIONS.indexOf(entry);
                if (failedIdx >= 0) {
                    ATTACHED_SSE_CONNECTIONS.splice(failedIdx, 1);
                }
                failAllSubscriptions();
            }
        };

        var entry = { url: url, source: source };
        ATTACHED_SSE_CONNECTIONS.push(entry);
        return function detach() {
            try { source.close(); } catch (e) { /* ignore */ }
            var idx = ATTACHED_SSE_CONNECTIONS.indexOf(entry);
            if (idx >= 0) {
                ATTACHED_SSE_CONNECTIONS.splice(idx, 1);
            }
        };
    }

    function parseSseFrame(ev) {
        if (!ev || typeof ev.data !== 'string' || ev.data === '') {
            return null;
        }
        var origin = ev.target && ev.target.__semitexaUrl;
        if (typeof origin === 'string' && typeof ev.lastEventId === 'string' && SSE_REPLAY_ID_RE.test(ev.lastEventId)) {
            SSE_LAST_REPLAY_ID[origin] = ev.lastEventId;
        }
        try {
            return JSON.parse(ev.data);
        } catch (err) {
            return null;
        }
    }

    /**
     * Apply one canonical typed `ui.patch` envelope from
     * `/__semitexa_kiss`. The envelope wraps a SINGLE patch body
     * alongside its `componentInstanceId` (the framework's
     * UiPatchMessage value object). We route the inner patch object
     * through the same safe applier the legacy SSE stream uses, with
     * a pseudo-captured carrying `instanceId` so the
     * `target_instance_mismatch` defense-in-depth check still fires.
     */
    function applyCanonicalUiPatch(parsed) {
        if (!parsed || typeof parsed !== 'object') {
            return;
        }
        var componentInstanceId = typeof parsed.componentInstanceId === 'string'
            ? parsed.componentInstanceId
            : null;
        var patch = parsed.patch && typeof parsed.patch === 'object' ? parsed.patch : null;
        if (patch === null) {
            return;
        }
        var pseudoCaptured = componentInstanceId !== null
            ? { instanceId: componentInstanceId, source: 'sse-canonical' }
            : { source: 'sse-canonical' };
        applyOnePatchForSse(patch, pseudoCaptured, 0);
    }

    /**
     * Feed SSE-delivered patches into the SAME safe applier the dispatch
     * transport uses. We synthesize a minimal `captured`-like envelope
     * carrying instanceId so applyOnePatch's defense-in-depth
     * instance-mismatch check still has something to compare against.
     * The patch's own `target.instance` remains authoritative.
     */
    function applySsePatches(patches) {
        if (!isArray(patches) || patches.length === 0) {
            return;
        }
        for (var i = 0; i < patches.length; i++) {
            var patch = patches[i];
            var instanceId = (patch && patch.target && typeof patch.target.instance === 'string')
                ? patch.target.instance
                : null;
            var ssePseudoCaptured = instanceId !== null
                ? { instanceId: instanceId, source: 'sse' }
                : { source: 'sse' };
            applyOnePatchForSse(patch, ssePseudoCaptured, i);
        }
    }

    function applyOnePatchForSse(patch, pseudoCaptured, index) {
        // Wrap the dispatch-path patch applier with SSE-specific
        // lifecycle event names — same validation rules, same DOM
        // operations. We listen on the dispatch events once and
        // re-emit under the sse namespace for any patch we applied in
        // this batch; that's noisier than necessary, so we instead
        // call applyOnePatch directly and rely on its lifecycle
        // emission, then ALSO emit the SSE-specific event.
        var prevAppliedHandler = null;
        var prevFailedHandler = null;
        var matchedApplied = false;
        var matchedFailed = false;
        function onApplied(ev) {
            if (matchedApplied) return;
            var d = ev.detail || {};
            if (d.index === index && d.patch === patch) {
                matchedApplied = true;
                emitTransportEvent('semitexa:ui-sse:patch-applied', {
                    patch: patch,
                    index: index
                });
            }
        }
        function onFailed(ev) {
            if (matchedFailed) return;
            var d = ev.detail || {};
            if (d.index === index && d.patch === patch) {
                matchedFailed = true;
                emitTransportEvent('semitexa:ui-sse:patch-failed', {
                    patch: patch,
                    index: index,
                    reason: d.reason
                });
            }
        }
        document.addEventListener('semitexa:ui-patch:applied', onApplied);
        document.addEventListener('semitexa:ui-patch:failed', onFailed);
        try {
            applyOnePatch(patch, pseudoCaptured, index);
        } finally {
            document.removeEventListener('semitexa:ui-patch:applied', onApplied);
            document.removeEventListener('semitexa:ui-patch:failed', onFailed);
        }
    }

    /**
     * Programmatic dispatch API for non-native gestures.
     *
     * The standard capture path (handleNativeEvent) only fires for DOM
     * events whose name matches a manifest entry (e.g. `submit`,
     * `change`, `click`). Component runtimes that synthesise their own
     * gesture vocabulary — grid sort, paginate, etc. — can publish
     * captures programmatically via this API. The synthesised capture
     * runs through the same notifyListeners pipeline as a native event,
     * so the auto-attached transport, custom-event observers, and form-
     * aggregate logic all behave identically.
     *
     * Caller responsibilities:
     *   - The instance MUST have a parsed manifest. Pages that omit the
     *     manifest script tag get a `false` return.
     *   - The (part, event) pair MUST match a manifest entry. Unknown
     *     pairs return `false` without firing anything.
     *   - `value` is forwarded verbatim into the captured object's
     *     `value` field. The transport wraps it as `payload.value`. Pass
     *     a structured object (`{col, dir}` for sort, `{cursor}` for
     *     paginate) when the gesture carries multi-key intent.
     *
     * Returns `true` when a captured object was published, `false`
     * otherwise. The boolean lets callers fall back to a legacy
     * transport (e.g. `fetch(/grid-data)`) when the new path is not
     * available for the current instance.
     */
    function dispatchProgrammatic(opts) {
        if (typeof opts !== 'object' || opts === null) {
            return false;
        }
        var instanceId = typeof opts.instanceId === 'string' ? opts.instanceId : '';
        var partName   = typeof opts.part === 'string' ? opts.part : '';
        var eventName  = typeof opts.event === 'string' ? opts.event : '';
        if (instanceId === '' || partName === '' || eventName === '') {
            return false;
        }
        var manifest = findManifestForInstance(instanceId);
        if (!manifest) {
            return false;
        }
        var entry = null;
        for (var i = 0; i < manifest.events.length; i++) {
            if (manifest.events[i].p === partName && manifest.events[i].e === eventName) {
                entry = manifest.events[i];
                break;
            }
        }
        if (entry === null) {
            return false;
        }
        var captured = {
            component: manifest.c,
            instanceId: manifest.i,
            part: entry.p,
            event: entry.e,
            updates: entry.u || null,
            ctx: entry.ctx,
            value: 'value' in opts ? opts.value : null,
            originalEvent: null,
            manifestVersion: manifest.v
        };
        if (typeof console !== 'undefined' && console.debug) {
            console.debug(
                '[semitexa-ui] dispatch (programmatic)',
                captured.component + '#' + captured.instanceId,
                captured.part + '.' + captured.event,
                captured
            );
        }
        try {
            document.dispatchEvent(new CustomEvent('semitexa:ui-event:captured', {
                detail: captured,
                bubbles: false,
                cancelable: false
            }));
        } catch (err) {
            if (typeof console !== 'undefined' && console.warn) {
                console.warn('[semitexa-ui] CustomEvent dispatch failed', err);
            }
        }
        notifyListeners(captured);
        return true;
    }

    window.SemitexaUi = Object.assign(window.SemitexaUi || {}, {
        version: '1.0',
        scan: scan,
        manifests: manifests,
        onCapture: onCapture,
        dispatch: dispatchProgrammatic,
        transport: {
            attach: attachTransport
        },
        sse: {
            attach: attachSse,
            subscribe: sseSubscribe,
            sessionId: readPageSseSessionId
        },
        forms: {
            snapshot: formAggregateSnapshot,
            reset: formAggregateReset
        },
        // Morph an element into freshly rendered HTML in place (focus, caret,
        // an open dialog survive): the island runtime's re-render.
        morph: function (from, to) {
            morphElement(from, to);
            scan(document);
        }
    });

    /**
     * Gated auto-attach for the canonical inbound transport.
     *
     * Trigger condition: at least one signed platform-ui component
     * manifest is present on the page AND no caller has already wired
     * a transport AND `fetch` is available AND the page has not opted
     * out via `window.SEMITEXA_UI_DISABLE_AUTOATTACH`.
     *
     * Why this is safe:
     *   - A signed manifest is an unambiguous server-issued opt-in.
     *     Non-platform-ui pages emit no manifest → no auto-attach →
     *     zero network impact.
     *   - The capture listener fires only for DOM events declared
     *     inside a parsed manifest. Other forms on the same page
     *     (e.g. a static `<form action="/checkout">`) are NOT
     *     captured — `handleNativeEvent` walks up to a
     *     `[data-ui-component-instance-id]` ancestor and bails out
     *     when there is none.
     *   - The auto-attached transport posts to HUG
     *     (`/__semitexa_hug`). A page that attaches its own transport
     *     first still wins (page-level script tags execute
     *     synchronously; the auto-attach runs after `scan()` +
     *     observer setup).
     *
     * Opt-out for tests / niche pages:
     *
     *     window.SEMITEXA_UI_DISABLE_AUTOATTACH = true;
     *
     * Must be set before the runtime script loads (it lives in the
     * IIFE's closure once evaluated).
     */
    function maybeAutoAttachTransport() {
        if (window.SEMITEXA_UI_DISABLE_AUTOATTACH === true) {
            return;
        }
        if (parsedManifests.length === 0) {
            return;
        }
        if (attachedTransports.length > 0) {
            return;
        }
        if (typeof fetch !== 'function') {
            return;
        }
        attachTransport({ endpoint: DEFAULT_TRANSPORT_ENDPOINT });
    }

    /**
     * Gated auto-open for the canonical SSE patch stream.
     *
     * Trigger condition: the page advertised a subscriber channel id
     * via `<meta name="semitexa-ui-sse-session" content="<id>">`, the
     * page has not opted out via `window.SEMITEXA_UI_DISABLE_AUTOATTACH`,
     * EventSource is available, and the id matches the safe
     * `[A-Za-z0-9][A-Za-z0-9_-]{0,127}` shape (same alphabet the
     * server-side dispatcher accepts on the signed `sub` claim).
     *
     * NOTE: there is intentionally NO `parsedManifests.length > 0`
     * gate. Pages that opt into the canonical stream may render
     * components without `#[UiOn]` handlers (e.g. a read-only
     * component that still wants to receive `ui.componentState`
     * frames). The meta tag is the explicit
     * opt-in; the manifest count is irrelevant for the SSE open
     * decision.
     *
     * Pages that never render the meta tag (the default for
     * components added before this slice) get no SSE auto-open and
     * the dispatcher keeps delivering patches inline — fully
     * backward-compatible.
     *
     * Why this is safe to run unconditionally on every platform-ui
     * page:
     *
     *   - The meta tag is an unambiguous server-issued opt-in. The
     *     id it carries is the same value the page's signed ctxs
     *     hold under `sub`, so the dispatcher can only publish into
     *     a channel that this very page minted.
     *   - `attachSse({url})` already deduplicates same-URL attaches,
     *     so even if a caller manually attached the same KISS URL
     *     first, no second EventSource opens.
     *   - The framework's existing `semitexa-twig.js` deferred SSR
     *     stream opens a DIFFERENT URL
     *     (`/__semitexa_kiss?session_id=<X>&deferred_request_id=<Y>`),
     *     so two distinct streams coexist without competing for the
     *     same channel.
     */
    var SSE_SESSION_ID_SAFE_RE = /^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/;
    var SSE_SESSION_META_NAME = 'semitexa-ui-sse-session';
    var SSE_TRANSPORT_MODE_META_NAME = 'semitexa-ui-transport-mode';
    var SSE_TRANSPORT_MODE_DRAIN = 'drain';
    var SSE_TRANSPORT_MODE_LIVE = 'live';

    function readPageSseSessionId() {
        if (!document.querySelector) {
            return null;
        }
        var meta = document.querySelector(
            'meta[name="' + SSE_SESSION_META_NAME + '"]'
        );
        if (!meta || !meta.getAttribute) {
            return null;
        }
        var raw = meta.getAttribute('content');
        if (typeof raw !== 'string' || raw === '') {
            return null;
        }
        if (!SSE_SESSION_ID_SAFE_RE.test(raw)) {
            if (typeof console !== 'undefined' && console.warn) {
                console.warn('[semitexa-ui] sse session id has unsafe shape; ignored');
            }
            return null;
        }
        return raw;
    }

    // ---- SSE transport unification · Phase 3: subscription multiplexer --------
    //
    // One KISS connection carries MANY feed subscriptions. `subscribe()` POSTs a
    // subscribe control to the feed route; the server attaches the feed to this
    // page's KISS session and pushes the feed's frames — each tagged with the
    // subscription's `streaming_id` — over the shared connection, which the
    // demux listeners in attachSse route back to the right callback. A consumer
    // (grid / collab form) thus rides the ONE connection instead of opening its
    // own EventSource. Degrades to a no-op when the page has no KISS session (the
    // caller then keeps its own-EventSource fallback).

    var SSE_SUBSCRIPTIONS = {}; // subscription_id -> { feedRef, params, onFrame }
    var SSE_DATA_FRAME_TYPE_LIST = [
        'ui.document.data', 'ui.document.error',
        'ui.collection.data', 'ui.collection.error', 'ui.collection.patch'
    ];

    /** True when a LIVE-mode KISS connection is already attached (URL carries mode=live). */
    function hasLiveSseConnection() {
        for (var i = 0; i < ATTACHED_SSE_CONNECTIONS.length; i++) {
            var u = ATTACHED_SSE_CONNECTIONS[i].url || '';
            if (u.indexOf('mode=' + SSE_TRANSPORT_MODE_LIVE) !== -1) {
                return true;
            }
        }
        return false;
    }

    /** Ensure a LIVE shared KISS connection is open (collab/grids need it live). */
    function ensureKissOpen(sessionId) {
        if (typeof EventSource !== 'function') {
            return false;
        }
        // A subscription needs a LIVE connection. Checking only the count was a
        // bug: a drain-mode connection (opened to flush deferred placeholders)
        // closes after the flush, so a subscription riding it would lose its feed.
        // Open the live URL whenever no LIVE connection is attached — a live page
        // already opened it (attachSse de-dupes the identical URL → no-op); a
        // drain-only page gets its live connection forced here by the subscriber.
        if (!hasLiveSseConnection()) {
            attachSse({ url: buildKissUrl(sessionId, SSE_TRANSPORT_MODE_LIVE) });
        }
        return true;
    }

    /** The params a feed control may carry: flat scalars only, nulls dropped. */
    function flatStreamParams(params) {
        var out = {};
        if (params) {
            for (var k in params) {
                if (!Object.prototype.hasOwnProperty.call(params, k)) {
                    continue;
                }
                var v = params[k];
                if (v === null || v === undefined) {
                    continue;
                }
                if (typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean') {
                    out[k] = v;
                }
            }
        }
        return out;
    }

    /**
     * Feed control goes to HUG — `{"stream": {op, feed, params, session,
     * subscriptionId}}` — and only acknowledges; every frame arrives on KISS.
     * op: subscribe | view | unsubscribe. Best-effort: a reconnect re-subscribes.
     */
    function postStreamControl(op, feed, params, sessionId, subscriptionId, patches) {
        if (typeof fetch !== 'function') {
            return;
        }
        var stream = { op: op, feed: feed, session: sessionId, subscriptionId: subscriptionId };
        // The subscriber applies keyed patches (ui.collection.patch).
        if (op === 'subscribe' && patches === true) {
            stream.patches = true;
        }
        if (op !== 'unsubscribe') {
            stream.params = flatStreamParams(params);
        }
        try {
            hug({ stream: stream }, { keepalive: true })
                .catch(function () { /* best-effort; reconnect re-subscribes */ });
        } catch (postErr) { /* ignore */ }
    }

    /**
     * Subscribe a feed to the shared KISS connection.
     *   feedRef = { feed } — the feed's route name (its OPTIONS contract `name`),
     *   params = feed query params (e.g. { ctx }),
     *   onFrame(frame) = called with each demuxed frame body,
     *   onUnavailable() = optional; the page's KISS stream failed before it
     *   ever connected, so this subscription is dropped and the caller pulls.
     * Returns { degraded, subscriptionId, view(params), unsubscribe() }. When
     * `degraded` is true the page has no KISS session — the caller pulls.
     */
    function sseSubscribe(feedRef, params, onFrame, onUnavailable) {
        var noop = { degraded: true, subscriptionId: null, view: function () {}, unsubscribe: function () {} };
        var sessionId = readPageSseSessionId();
        if (sessionId === null || typeof EventSource !== 'function' || typeof fetch !== 'function'
            || !feedRef || typeof feedRef.feed !== 'string' || feedRef.feed === ''
            || typeof onFrame !== 'function') {
            return noop;
        }

        ensureKissOpen(sessionId);

        var subscriptionId = mintHexPrefixedId('sse_', 16); // sse_<32hex>
        var patches = feedRef.patches === true;
        SSE_SUBSCRIPTIONS[subscriptionId] = { feed: feedRef.feed, params: params || {}, onFrame: onFrame, onUnavailable: onUnavailable, patches: patches };
        postStreamControl('subscribe', feedRef.feed, params, sessionId, subscriptionId, patches);

        return {
            degraded: false,
            subscriptionId: subscriptionId,
            view: function (nextParams) {
                var s = SSE_SUBSCRIPTIONS[subscriptionId];
                if (!s) {
                    return;
                }
                // A reconnect re-subscribes with the CURRENT view, not the first one.
                s.params = nextParams || {};
                postStreamControl('view', s.feed, s.params, sessionId, subscriptionId);
            },
            unsubscribe: function () {
                if (!SSE_SUBSCRIPTIONS[subscriptionId]) {
                    return;
                }
                delete SSE_SUBSCRIPTIONS[subscriptionId];
                postStreamControl('unsubscribe', feedRef.feed, null, sessionId, subscriptionId);
            }
        };
    }

    /** The page's KISS stream cannot open: every subscription falls back to pull. */
    function failAllSubscriptions() {
        var ids = Object.keys(SSE_SUBSCRIPTIONS);
        for (var i = 0; i < ids.length; i++) {
            var s = SSE_SUBSCRIPTIONS[ids[i]];
            delete SSE_SUBSCRIPTIONS[ids[i]];
            if (s && typeof s.onUnavailable === 'function') {
                try { s.onUnavailable(); } catch (cbErr) { /* one feed must not break the rest */ }
            }
        }
    }

    /** Re-POST every active subscribe (same ids) after the shared connection reconnects. */
    function resubscribeAll() {
        var sessionId = readPageSseSessionId();
        if (sessionId === null) {
            return;
        }
        for (var id in SSE_SUBSCRIPTIONS) {
            if (Object.prototype.hasOwnProperty.call(SSE_SUBSCRIPTIONS, id)) {
                var s = SSE_SUBSCRIPTIONS[id];
                postStreamControl('subscribe', s.feed, s.params, sessionId, id, s.patches === true);
            }
        }
    }

    /** Route a demuxed data frame to its subscription callback. True iff handled. */
    function routeSubscriptionFrame(parsed) {
        if (!parsed || typeof parsed._type !== 'string') {
            return false;
        }
        if (SSE_DATA_FRAME_TYPE_LIST.indexOf(parsed._type) === -1) {
            return false;
        }
        var sid = parsed.streaming_id;
        if (typeof sid !== 'string' || !SSE_SUBSCRIPTIONS[sid]) {
            return false;
        }
        try {
            SSE_SUBSCRIPTIONS[sid].onFrame(parsed);
        } catch (cbErr) { /* a consumer callback must never break the demux loop */ }
        return true;
    }

    if (typeof document !== 'undefined' && document.addEventListener) {
        // The shared connection's reconnect (a gap-detected re-`connected`) re-arms
        // every subscription server-side with the same id.
        document.addEventListener('semitexa:ui-sse:reconnected', function () {
            resubscribeAll();
        }, false);
    }

    /**
     * Read the canonical transport mode the server-side helper baked
     * into `<meta name="semitexa-ui-transport-mode">`. The server-side
     * policy (PlatformUiTransportModePolicy) already enforces the
     * allow-list and resolves the default — we re-validate on the
     * client purely as defence-in-depth so a hand-edited meta cannot
     * smuggle a non-allow-listed mode into the KISS URL.
     *
     * Unknown / missing values fall back to drain. That is the safe
     * default for public/guest pages: the runtime will not open a
     * long-lived EventSource on DOMContentLoaded.
     */
    function readPageTransportMode() {
        if (!document.querySelector) {
            return SSE_TRANSPORT_MODE_DRAIN;
        }
        var meta = document.querySelector(
            'meta[name="' + SSE_TRANSPORT_MODE_META_NAME + '"]'
        );
        if (!meta || !meta.getAttribute) {
            return SSE_TRANSPORT_MODE_DRAIN;
        }
        var raw = meta.getAttribute('content');
        if (raw === SSE_TRANSPORT_MODE_LIVE) {
            return SSE_TRANSPORT_MODE_LIVE;
        }
        if (raw === SSE_TRANSPORT_MODE_DRAIN) {
            return SSE_TRANSPORT_MODE_DRAIN;
        }
        if (typeof console !== 'undefined' && console.warn) {
            console.warn(
                '[semitexa-ui] unknown transport mode meta value; falling back to drain'
            );
        }
        return SSE_TRANSPORT_MODE_DRAIN;
    }

    // The deferred manifest arrives as a <script type="application/json"> data
    // block, not as an executable assignment — an inline assignment needs a
    // nonce under a strict script-src and fails silently without one. This is
    // the same reader semitexa-ssr's own runtime carries; both memoize onto
    // window.__SSR_DEFERRED, so load order between the two does not matter and
    // the parse happens once. Deliberately duplicated rather than imported:
    // that runtime is a classic script, and this one must not depend on it
    // having run.
    function readDeferredManifest() {
        if (typeof window === 'undefined' || typeof document === 'undefined') return null;
        // `document.scripts`, not a selector. The LAST block is the one the
        // server treats as authoritative — a response can append an updated
        // manifest after an earlier one is already in the document — and this
        // file is under a rule that no querySelectorAll may run against
        // `document`. A live collection walked backwards answers the same
        // question with no selector at all.
        if (!document.scripts) return window.__SSR_DEFERRED || null;
        var el = null;
        for (var i = document.scripts.length - 1; i >= 0; i--) {
            var candidate = document.scripts[i];
            if (candidate.type === 'application/json' && candidate.hasAttribute('data-ssr-deferred-manifest')) {
                el = candidate;
                break;
            }
        }
        // The ELEMENT decides, and the memo only saves re-parsing it. A shell
        // navigation swaps regions without replacing `window`, so returning the
        // memo first handed the new page the previous one's requestId, session
        // and bind token — and its skeletons then waited for frames addressed
        // to a request that had already finished.
        if (el && window.__SSR_DEFERRED_EL === el && window.__SSR_DEFERRED) {
            return window.__SSR_DEFERRED;
        }
        if (!el) {
            window.__SSR_DEFERRED = null;
            window.__SSR_DEFERRED_EL = null;
            return null;
        }
        var parsed;
        try {
            parsed = JSON.parse(el.textContent || '');
        } catch (e) {
            return null;
        }
        if (!parsed || typeof parsed !== 'object') return null;
        window.__SSR_DEFERRED = parsed;
        window.__SSR_DEFERRED_EL = el;
        return parsed;
    }

    function buildKissUrl(sessionId, mode) {
        var url = '/__semitexa_kiss?session_id=' + encodeURIComponent(sessionId)
            + '&mode=' + encodeURIComponent(mode);
        // Unify the deferred-SSR stream into this connection: when the page
        // emitted deferred placeholders, append the one-shot deferred request
        // id so the server streams the deferred slots over the SAME connection
        // (regardless of mode) before it drains/holds per the transport mode.
        // The id is consumed server-side, so it only ever rides the initial
        // open; pages with no deferred content (the drain-on-demand case) have
        // no window.__SSR_DEFERRED and get a plain session+mode URL.
        var deferred = readDeferredManifest();
        if (deferred && typeof deferred.requestId === 'string' && deferred.requestId !== '') {
            url += '&deferred_request_id=' + encodeURIComponent(deferred.requestId);
        }
        return url;
    }

    // De-dupe state for the drain-on-demand listener. Without this
    // a second qualifying `semitexa:ui-event:dispatched` (a second
    // form submit during the same page lifetime) would attempt to
    // re-attach — attachSse's same-URL dedupe already prevents a
    // second EventSource, but bailing out here avoids the wasted
    // closure work and keeps the wire log clean.
    var drainOnDemandArmed = false;
    var drainOnDemandOpened = false;
    // Streamed answers that arrived while a drain was already open: the server
    // may have queued them just as that drain was closing, so a close with any
    // outstanding reopens once. Without this a drain page received only its
    // FIRST streamed answer — every later one waited on the server for good.
    var drainStreamedWhileOpen = 0;

    function armDrainOnDemand(sessionId) {
        if (drainOnDemandArmed) {
            return;
        }
        drainOnDemandArmed = true;
        var drainUrl = function () { return buildKissUrl(sessionId, SSE_TRANSPORT_MODE_DRAIN); };
        document.addEventListener('semitexa:ui-sse:close', function (ev) {
            var url = ev && ev.detail ? ev.detail.url : '';
            if (typeof url !== 'string' || url.indexOf('mode=' + SSE_TRANSPORT_MODE_DRAIN) === -1) {
                return;
            }
            drainOnDemandOpened = false;
            if (drainStreamedWhileOpen > 0) {
                drainStreamedWhileOpen = 0;
                drainOnDemandOpened = true;
                attachSse({ url: drainUrl() });
            }
        }, false);
        document.addEventListener('semitexa:ui-event:dispatched', function (ev) {
            var detail = ev && ev.detail ? ev.detail : null;
            if (!detail || !detail.response || typeof detail.response !== 'object') {
                return;
            }
            // The dispatcher emits `streamedPatchCount` /
            // `streamedStateCount` only when at least one patch or a
            // whole-component state frame was published over the
            // canonical stream; absent / zero on both means we already
            // received the response inline and there is nothing to drain.
            var streamedPatches = detail.response.streamedPatchCount;
            var streamedState = detail.response.streamedStateCount;
            var streamed = (typeof streamedPatches === 'number' ? streamedPatches : 0)
                         + (typeof streamedState === 'number' ? streamedState : 0);
            if (streamed <= 0) {
                return;
            }
            if (drainOnDemandOpened) {
                drainStreamedWhileOpen += streamed;
                return;
            }
            drainOnDemandOpened = true;
            drainStreamedWhileOpen = 0;
            attachSse({ url: drainUrl() });
        }, false);
    }

    function maybeAutoOpenSse() {
        if (window.SEMITEXA_UI_DISABLE_AUTOATTACH === true) {
            return;
        }
        // No `parsedManifests.length > 0` gate here — see the function
        // docblock above. The session-id meta tag IS the opt-in.
        if (typeof EventSource !== 'function') {
            return;
        }
        var sessionId = readPageSseSessionId();
        if (sessionId === null) {
            return;
        }
        var mode = readPageTransportMode();
        var deferred = readDeferredManifest();
        var hasDeferred = !!(deferred
            && typeof deferred.requestId === 'string'
            && deferred.requestId !== '');
        if (mode === SSE_TRANSPORT_MODE_LIVE || hasDeferred) {
            // Single EventSource owner per page. Open eagerly when the page is
            // live (holds the stream open for the lifetime of the view) OR has
            // deferred placeholders to fill (buildKissUrl appends the deferred
            // request id, so the deferred slots stream over this connection).
            // A live page drains deferred then stays open for UI events; a
            // drain page with deferred drains the deferred slots then closes
            // (server keepChannelOpen=false in drain). attachSse de-dupes by
            // URL so a second attach to the same unified URL is a no-op.
            if (hasDeferred && typeof deferred.bindToken === 'string' && deferred.bindToken !== '') {
                // The server's deferred admit (bind-token gate) requires this
                // cookie whenever deferred_request_id is sent — set it before
                // opening so the unified connection is admitted. Previously the
                // deferred-SSR runtime (semitexa-twig.js) set it, but it no
                // longer opens its own stream when this runtime owns the page.
                document.cookie = 'semitexa_ssr_bind='
                    + encodeURIComponent(deferred.bindToken)
                    + '; Path=/; SameSite=Lax';
            }
            attachSse({
                url: buildKissUrl(sessionId, mode)
            });
            return;
        }
        // Drain mode with NO deferred content: do NOT open an EventSource on
        // DOMContentLoaded. Arm a one-shot listener that opens the drain stream
        // only when a canonical UI event reports streamedPatchCount > 0. The
        // server flushes the queue + emits `event: close`, and the close-event
        // handler below tears the EventSource down, so there is no long-lived
        // connection for public/guest pages.
        armDrainOnDemand(sessionId);
    }

    /**
     * Revive any non-OPEN canonical SSE connection when the tab regains
     * focus. Background tabs are throttled by the browser, so a stream that
     * died while hidden may be stuck in CONNECTING (slow native reconnect)
     * or CLOSED. On `visible` we deterministically close and re-open each
     * attached connection that is not OPEN, through the same attachSse path
     * — the server answers with a fresh `connected` frame, which the
     * listener above turns into a `semitexa:ui-sse:reconnected` signal.
     *
     * Healthy (OPEN) connections are left untouched. Cleanly-drained
     * one-shot streams are already removed from ATTACHED_SSE_CONNECTIONS by
     * their `close` handler, so they are never revived here.
     */
    function reviveSseConnections() {
        if (typeof EventSource !== 'function') {
            return;
        }
        var OPEN = (typeof EventSource.OPEN === 'number') ? EventSource.OPEN : 1;
        // Snapshot — we mutate ATTACHED_SSE_CONNECTIONS while iterating.
        var entries = ATTACHED_SSE_CONNECTIONS.slice();
        for (var i = 0; i < entries.length; i++) {
            var entry = entries[i];
            var readyState = (entry && entry.source) ? entry.source.readyState : 2;
            if (readyState === OPEN) {
                continue;
            }
            try { entry.source.close(); } catch (e) { /* ignore */ }
            var idx = ATTACHED_SSE_CONNECTIONS.indexOf(entry);
            if (idx >= 0) {
                ATTACHED_SSE_CONNECTIONS.splice(idx, 1);
            }
            // attachSse de-dupes by url; we removed the dead entry first, so
            // a fresh EventSource opens for the same url.
            attachSse({ url: entry.url });
        }
    }

    if (typeof document.addEventListener === 'function') {
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                reviveSseConnections();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            scan();
            startObserver();
            maybeAutoAttachTransport();
            maybeAutoOpenSse();
        });
    } else {
        scan();
        startObserver();
        maybeAutoAttachTransport();
        maybeAutoOpenSse();
    }
})();

/*
 * ESM surface — importable as 'platform-ui/events'. Same objects the
 * window.SemitexaUi API exposes (the window surface stays until every
 * consumer of the shared KISS manager imports this module instead).
 */
const __events = window.SemitexaUi;
export const version = __events.version;
export const scan = __events.scan;
export const manifests = __events.manifests;
export const onCapture = __events.onCapture;
export const dispatch = __events.dispatch;
export const transport = __events.transport;
export const sse = __events.sse;
export const forms = __events.forms;
export const morph = __events.morph;
