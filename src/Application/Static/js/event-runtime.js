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
(function () {
    'use strict';

    if (window.SemitexaUi) {
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
        if (!partEl) {
            return null;
        }
        // <input>, <select>, <textarea> all expose `.value`.
        if ('value' in partEl) {
            try {
                return partEl.value;
            } catch (err) {
                return null;
            }
        }
        // Fallback for elements that only expose a value attribute.
        if (partEl.getAttribute) {
            return partEl.getAttribute('value');
        }
        return null;
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
                manifestVersion: manifest.v
            };

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

            notifyListeners(captured);
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

    function startObserver() {
        if (typeof MutationObserver === 'undefined' || !document.body) {
            return;
        }
        var observer = new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                var added = mutations[i].addedNodes;
                if (!added || !added.length) {
                    continue;
                }
                for (var j = 0; j < added.length; j++) {
                    var node = added[j];
                    if (!node || node.nodeType !== 1) {
                        continue;
                    }
                    if (node.matches && node.matches(
                        'script[type="application/json"][data-ui-event-manifest]'
                    )) {
                        scanRoot(node.parentNode || document);
                    } else if (node.querySelector && node.querySelector(
                        'script[type="application/json"][data-ui-event-manifest]'
                    )) {
                        scanRoot(node);
                    }
                }
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });
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
     * The same generator is also used for `eventId` on the canonical
     * `/__ui/event` envelope (Phase 3 Part 2 — the adapter maps
     * `eventId → dispatchId` 1:1 for replay protection, so the strict
     * pattern matters in both transports).
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
     * Default endpoint for the canonical inbound `POST /__ui/event`
     * (semitexa-ssr's `UiEventEndpointHandler`). The legacy
     * `/__ui/dispatch` endpoint remains as a compatibility shim — direct
     * callers passing `attachTransport({ endpoint: '/__ui/dispatch' })`
     * still work and continue to receive the legacy `{ctx, dispatchId,
     * payload}` wire body so the server-side decoder stays unchanged.
     */
    var DEFAULT_TRANSPORT_ENDPOINT = '/__ui/event';
    var CANONICAL_TRANSPORT_ENDPOINT = '/__ui/event';
    var ENVELOPE_SCHEMA_VERSION = 1;

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

            // Body shape depends on the endpoint. The canonical
            // `/__ui/event` route in semitexa-ssr decodes a
            // `UiEventEnvelope`; the legacy `/__ui/dispatch` route in
            // semitexa-platform-ui decodes the older
            // `{ctx, dispatchId, payload}` shape. We branch on the
            // string match so direct callers that explicitly opt into
            // `/__ui/dispatch` (e.g. demo pages) keep their legacy
            // wire contract intact, while the new default
            // (`/__ui/event`) gets the canonical envelope.
            var body;
            var correlationId = generateCorrelationId();
            var semanticEvent = deriveSemanticEvent(captured);
            try {
                if (endpoint === CANONICAL_TRANSPORT_ENDPOINT) {
                    body = JSON.stringify({
                        schemaVersion: ENVELOPE_SCHEMA_VERSION,
                        eventId: dispatchId,
                        correlationId: correlationId,
                        semanticEvent: semanticEvent,
                        signedContext: captured.ctx,
                        timestamp: new Date().toISOString(),
                        payload: payloadObj
                    });
                } else {
                    body = JSON.stringify({
                        ctx: captured.ctx,
                        dispatchId: dispatchId,
                        payload: payloadObj
                    });
                }
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
                endpoint: endpoint
            });

            fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: body
            }).then(function (resp) {
                return resp.text().then(function (text) {
                    var parsed = null;
                    try { parsed = text ? JSON.parse(text) : null; } catch (parseErr) {
                        parsed = null;
                    }
                    if (resp.ok) {
                        emitTransportEvent('semitexa:ui-event:dispatched', {
                            captured: captured,
                            dispatchId: dispatchId,
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
                emitTransportEvent('semitexa:ui-event:failed', {
                    captured: captured,
                    dispatchId: dispatchId,
                    error: err,
                    phase: 'network'
                });
            });
        });

        var entry = { endpoint: endpoint, unsubscribe: unsubscribe };
        attachedTransports.push(entry);
        return function detach() {
            entry.unsubscribe();
            var idx = attachedTransports.indexOf(entry);
            if (idx >= 0) {
                attachedTransports.splice(idx, 1);
            }
        };
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
     *   - never uses innerHTML, never `eval`s, never executes scripts;
     *   - emits semitexa:ui-patch:applied / :failed lifecycle events per
     *     patch — one failed patch never breaks the rest of the batch.
     */
    var ALLOWED_PATCH_OPS = { setText: true, setValue: true, setAttribute: true };
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
        // Defense in depth: even though the server already pins the
        // patch instance to the signed event, double-check on the
        // client that the response we just received corresponds to the
        // captured event we just sent.
        if (captured && captured.instanceId && captured.instanceId !== instanceId) {
            return failPatch(patch, captured, index, 'target_instance_mismatch');
        }
        var rootEl = document.querySelector(
            '[data-ui-component-instance-id="' + cssAttrEscape(instanceId) + '"]'
        );
        if (!rootEl) {
            return failPatch(patch, captured, index, 'root_not_found');
        }

        var el = resolveTargetElement(rootEl, target, patch, captured, index);
        if (!el) return; // failPatch already emitted by resolveTargetElement

        switch (op) {
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
            var namedEl = rootEl.querySelector(
                '[data-ui-patch-target="' + cssAttrEscape(name) + '"]'
            );
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
            // The field's input part is the only thing we read. No
            // textareas / selects / checkboxes in this slice —
            // those surfaces will be added together with the
            // primitives that render them.
            var inputEl = fieldEl.querySelector('[data-ui-part="input"]');
            if (!inputEl || !('value' in inputEl)) {
                continue;
            }
            var rawValue;
            try {
                rawValue = inputEl.value;
            } catch (e) {
                continue;
            }
            if (rawValue === null || rawValue === undefined) {
                snapshot[name] = null;
                continue;
            }
            // The DOM `.value` is always a string in this slice; we
            // coerce defensively so a future surface that returns
            // numbers doesn't accidentally smuggle objects through.
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
     *     applyResponsePatches path used by POST /__ui/dispatch
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
            source = new EventSource(url, { withCredentials: false });
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
            // Shape-detect: canonical typed message from /__semitexa_kiss
            // carries `_type: 'ui.patch'` + a single `patch` body and
            // wraps it in a `componentInstanceId` envelope. The legacy
            // /__ui/stream shape is `{v, patches[]}`. Both routes share
            // this listener; we route by shape, not by URL.
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
            emitTransportEvent('semitexa:ui-sse:close', {
                detail: parsed,
                url: url
            });
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
        });

        source.onerror = function (err) {
            emitTransportEvent('semitexa:ui-sse:error', {
                phase: 'transport',
                error: err,
                url: url
            });
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

    window.SemitexaUi = {
        version: '1.0',
        scan: scan,
        manifests: manifests,
        onCapture: onCapture,
        transport: {
            attach: attachTransport
        },
        sse: {
            attach: attachSse
        },
        forms: {
            snapshot: formAggregateSnapshot,
            reset: formAggregateReset
        }
    };

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
     *   - The auto-attached transport uses the canonical
     *     `/__ui/event` endpoint. Direct callers explicitly attaching
     *     `/__ui/dispatch` still win because they call before us
     *     (page-level script tags execute synchronously; the auto-
     *     attach runs after `scan()` + observer setup).
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
     * Trigger condition: at least one signed platform-ui manifest is
     * parsed AND the page advertised a subscriber channel id via
     * `<meta name="semitexa-ui-sse-session" content="<id>">` AND the
     * page has not opted out via `window.SEMITEXA_UI_DISABLE_AUTOATTACH`
     * AND EventSource is available AND the id matches the safe
     * `[A-Za-z0-9][A-Za-z0-9_-]{0,127}` shape (same alphabet the
     * server-side dispatcher accepts on the signed `sub` claim).
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

    function buildKissUrl(sessionId, mode) {
        return '/__semitexa_kiss?session_id=' + encodeURIComponent(sessionId)
            + '&mode=' + encodeURIComponent(mode);
    }

    // De-dupe state for the drain-on-demand listener. Without this
    // a second qualifying `semitexa:ui-event:dispatched` (a second
    // form submit during the same page lifetime) would attempt to
    // re-attach — attachSse's same-URL dedupe already prevents a
    // second EventSource, but bailing out here avoids the wasted
    // closure work and keeps the wire log clean.
    var drainOnDemandArmed = false;
    var drainOnDemandOpened = false;

    function armDrainOnDemand(sessionId) {
        if (drainOnDemandArmed) {
            return;
        }
        drainOnDemandArmed = true;
        document.addEventListener('semitexa:ui-event:dispatched', function (ev) {
            if (drainOnDemandOpened) {
                return;
            }
            var detail = ev && ev.detail ? ev.detail : null;
            if (!detail || !detail.response || typeof detail.response !== 'object') {
                return;
            }
            // The dispatcher emits `streamedPatchCount` only when at
            // least one patch was published over the canonical
            // stream; absent / zero means we already received inline
            // patches and there is nothing to drain.
            var streamed = detail.response.streamedPatchCount;
            if (typeof streamed !== 'number' || streamed <= 0) {
                return;
            }
            drainOnDemandOpened = true;
            attachSse({
                url: buildKissUrl(sessionId, SSE_TRANSPORT_MODE_DRAIN)
            });
        }, false);
    }

    function maybeAutoOpenSse() {
        if (window.SEMITEXA_UI_DISABLE_AUTOATTACH === true) {
            return;
        }
        if (parsedManifests.length === 0) {
            return;
        }
        if (typeof EventSource !== 'function') {
            return;
        }
        var sessionId = readPageSseSessionId();
        if (sessionId === null) {
            return;
        }
        var mode = readPageTransportMode();
        if (mode === SSE_TRANSPORT_MODE_LIVE) {
            // Live pages hold the stream open for the lifetime of the
            // view — same EventSource the page was opening before the
            // policy split, now with explicit mode=live so the server
            // resolver enters the long-lived branch deterministically.
            attachSse({
                url: buildKissUrl(sessionId, SSE_TRANSPORT_MODE_LIVE)
            });
            return;
        }
        // Drain mode (or any value the safe-shape guard fell back to
        // drain on): do NOT open an EventSource on DOMContentLoaded.
        // Arm a one-shot listener that opens the drain stream only
        // when a canonical UI event reports streamedPatchCount > 0.
        // The server flushes the queue + emits `event: close`, and
        // the close-event handler below tears the EventSource down,
        // so there is no long-lived connection for public/guest pages.
        armDrainOnDemand(sessionId);
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
