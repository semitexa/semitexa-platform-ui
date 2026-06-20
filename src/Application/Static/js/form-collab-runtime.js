/**
 * Collaborative Form Data · Phase 3 (Shared mode) — form-collab-runtime.js
 *
 * The browser half of live collaborative forms. The document-feed sibling of
 * grid-runtime-v2.js: where the grid runtime subscribes a list route and
 * re-renders rows on `ui.collection.data`, this subscribes ONE collaborative
 * document at `/__ui/form-doc` and re-applies field values on
 * `ui.document.data`. It mirrors the grid runtime's transport verbatim — native
 * EventSource, server-minted stream-id adoption, exponential-backoff reconnect,
 * a DOMContentLoaded + MutationObserver boot scan — and adds the form-specific
 * behaviour: apply remote field deltas, render the presence roster, and emit
 * each local edit back as a `field.edit` event on the canonical `/__ui/event`
 * write path (the same signed-context envelope event-runtime.js uses).
 *
 * TRUST: every server call carries a signed context token minted server-side
 * (the collab manifest). The feed token carries the trusted `cfg.scope/mode`;
 * the per-event tokens carry the (component, part, event) the dispatcher routes
 * by. The client never names a scope or a handler — it only relays opaque
 * tokens, exactly as the grid/event runtimes do.
 *
 * ECHO-SUPPRESSION: the feed re-projects the WHOLE document on every touch,
 * including the edit the local user just made. Applying that back would clobber
 * the field they are still typing into. Two guards prevent it: (1) never write
 * a field that is currently focused or locally dirty; (2) skip a snapshot whose
 * `origin` is this participant (`self`). Guard (1) is the robust one; (2) is the
 * documented fast-path.
 *
 * Manifest contract (emitted by the server `ui_collab_manifest()` helper as
 * `<script type="application/json" data-ui-collab-manifest>`):
 *   {
 *     v, i,                       schema version + render instance id
 *     scope, mode, self,          document scope, collaboration mode, my id
 *     feedUrl, feedCtx,           SSE read feed + its signed cfg token
 *     eventUrl, events,           write endpoint + { "field.edit": ctx, "presence.ping": ctx }
 *     fields, heartbeatMs         managed field names + presence cadence
 *   }
 */
(function () {
    'use strict';

    var SCHEMA_VERSION = 1;
    var MANIFEST_SELECTOR = 'script[type="application/json"][data-ui-collab-manifest]';
    var FIELD_SELECTOR = '[data-ui-field-name]';
    var PRESENCE_SELECTOR = '[data-ui-collab-presence]';
    var STATUS_SELECTOR = '[data-ui-collab-status]';
    var DEFAULT_HEARTBEAT_MS = 15000;
    var MAX_BACKOFF_MS = 30000;

    /** Booted instances, keyed by render instance id, so a re-scan is idempotent. */
    var booted = Object.create(null);

    // ---- small helpers ----------------------------------------------------

    function mintHexId(prefix, hexLen) {
        var hex = '';
        try {
            var buf = new Uint8Array(hexLen / 2);
            (window.crypto || window.msCrypto).getRandomValues(buf);
            for (var i = 0; i < buf.length; i++) {
                hex += ('0' + buf[i].toString(16)).slice(-2);
            }
        } catch (e) {
            while (hex.length < hexLen) {
                hex += Math.floor(Math.random() * 16).toString(16);
            }
        }
        return prefix + hex.slice(0, hexLen);
    }

    function nowIso() {
        // Date is unavailable in some sandboxes only; the browser always has it.
        return new Date().toISOString();
    }

    function parseManifest(scriptEl) {
        var raw = scriptEl.textContent || '';
        var data;
        try {
            data = JSON.parse(raw);
        } catch (e) {
            return null;
        }
        if (!data || data.v !== SCHEMA_VERSION || typeof data.feedUrl !== 'string' || typeof data.feedCtx !== 'string') {
            return null;
        }
        return data;
    }

    // ---- one collaborative form instance ----------------------------------

    function CollabForm(rootEl, manifest) {
        this.root = rootEl;
        this.m = manifest;
        this.sub = null;
        this.source = null;
        this.streamId = null;
        this.reconnectAttempts = 0;
        this.reconnectTimer = null;
        this.heartbeatTimer = null;
        this.closed = false;
        // Fields the local user is actively editing — never overwritten by a
        // remote snapshot until they blur and the value is no longer dirty.
        this.dirty = Object.create(null);
        this.self = String(manifest.self || '');
        this.heartbeatMs = manifest.heartbeatMs > 0 ? manifest.heartbeatMs : DEFAULT_HEARTBEAT_MS;
        this.mode = String(manifest.mode || 'shared');
        // Optimistic-mode state: the document version the local edits are based
        // on (`version`, frozen once there are unsaved edits so a stale save
        // genuinely conflicts), the latest version the server has announced, the
        // latest server values (for "take theirs"), and whether there are
        // uncommitted local edits.
        this.version = 0;
        this.latestServerVersion = 0;
        this.serverValues = Object.create(null);
        this.dirtyDoc = false;
        // Lock-mode state (FormLock / FieldLock): whether this participant holds
        // the whole-form lock, the current holder (from the projected snapshot),
        // and the lock-renewal timer.
        this.iHoldLock = false;
        this.lockHolderId = null;
        this.lockHolderLabel = null;
        this.lockHeartbeatTimer = null;
        // FieldLock state: the holder of each field (from the snapshot) and the
        // set of fields THIS participant currently holds, plus their shared
        // renewal timer.
        this.fieldLockHolders = Object.create(null);
        this.myFieldLocks = Object.create(null);
        this.fieldHeartbeatTimer = null;
    }

    CollabForm.prototype.fieldEl = function (name) {
        return this.root.querySelector(FIELD_SELECTOR + '[data-ui-field-name="' + cssEscape(name) + '"]');
    };

    CollabForm.prototype.inputOf = function (fieldEl) {
        if (!fieldEl) {
            return null;
        }
        if (fieldEl.matches && fieldEl.matches('input, select, textarea')) {
            return fieldEl;
        }
        return fieldEl.querySelector('input, select, textarea');
    };

    CollabForm.prototype.start = function () {
        this.wireLocalEdits();
        this.subscribe();
        this.startHeartbeat();
        if (this.mode === 'optimistic') {
            this.wireOptimisticSave();
        }
    };

    // -- transport: ride the ONE shared KISS connection (SSE transport
    //    unification), degrading to a dedicated EventSource only when the
    //    shared subscriber is unavailable -----------------------------------

    CollabForm.prototype.subscribe = function () {
        if (this.closed) {
            return;
        }
        var mgr = window.SemitexaUi && window.SemitexaUi.sse;
        if (mgr && typeof mgr.subscribe === 'function') {
            var self = this;
            var handle = mgr.subscribe(
                { url: this.m.feedUrl },
                { ctx: this.m.feedCtx },
                function (frame) { self.onFrame(frame); }
            );
            if (handle && !handle.degraded) {
                // Multiplexed over the page's single connection — no per-form
                // EventSource, no per-form reconnect (the shared connection owns
                // both; resubscribe-on-reconnect is automatic).
                this.sub = handle;
                this.setStatus('live');
                return;
            }
        }
        // Degrade (no KISS session / no EventSource / asset ordering): keep the
        // legacy dedicated stream so collab still works standalone.
        this.openStream();
    };

    /**
     * A frame the shared subscriber demuxed to THIS subscription (by streaming_id);
     * dispatch by its type. The body is the same `{_type, data, meta}` envelope the
     * dedicated EventSource path parses, so applySnapshot/onDocumentError are reused.
     */
    CollabForm.prototype.onFrame = function (frame) {
        if (!frame || typeof frame._type !== 'string') {
            return;
        }
        if (frame._type === 'ui.document.data') {
            this.reconnectAttempts = 0;
            this.applySnapshot(frame);
        } else if (frame._type === 'ui.document.error') {
            this.onDocumentError(frame);
        }
    };

    // -- legacy dedicated stream (degrade path; mirrors grid-runtime-v2) -----

    CollabForm.prototype.openStream = function () {
        if (this.closed) {
            return;
        }
        this.closeSource();

        var url = this.m.feedUrl + (this.m.feedUrl.indexOf('?') === -1 ? '?' : '&') + 'ctx=' + encodeURIComponent(this.m.feedCtx);
        var self = this;
        var source;
        try {
            source = new EventSource(url, { withCredentials: true });
        } catch (e) {
            this.scheduleReconnect();
            return;
        }
        this.source = source;

        source.addEventListener('ui.stream.id', function (ev) {
            try {
                var body = JSON.parse(ev.data);
                if (body && typeof body.stream_id === 'string') {
                    self.streamId = body.stream_id;
                }
            } catch (e) { /* ignore malformed id frame */ }
        });

        source.addEventListener('ui.document.data', function (ev) {
            self.reconnectAttempts = 0;
            var envelope;
            try {
                envelope = JSON.parse(ev.data);
            } catch (e) {
                return;
            }
            self.applySnapshot(envelope);
        });

        source.addEventListener('ui.document.error', function (ev) {
            var envelope;
            try {
                envelope = JSON.parse(ev.data);
            } catch (e) {
                envelope = {};
            }
            self.onDocumentError(envelope);
        });

        source.onerror = function () {
            self.setStatus('reconnecting');
            self.closeSource();
            self.scheduleReconnect();
        };
    };

    CollabForm.prototype.scheduleReconnect = function () {
        if (this.closed || this.reconnectTimer !== null) {
            return;
        }
        var delay = Math.min(MAX_BACKOFF_MS, 1000 * Math.pow(2, this.reconnectAttempts));
        this.reconnectAttempts += 1;
        var self = this;
        this.reconnectTimer = setTimeout(function () {
            self.reconnectTimer = null;
            self.openStream();
        }, delay);
    };

    CollabForm.prototype.closeSource = function () {
        if (this.source) {
            try { this.source.close(); } catch (e) { /* noop */ }
            this.source = null;
        }
    };

    // -- applying a remote snapshot -----------------------------------------

    CollabForm.prototype.applySnapshot = function (envelope) {
        this.setStatus('live');
        var data = envelope && envelope.data ? envelope.data : {};
        var meta = envelope && envelope.meta ? envelope.meta : {};

        // Echo-suppression fast-path: a snapshot this participant caused does
        // not need re-applying (guard (1) below still protects other fields if
        // origin attribution is coarse).
        var mine = this.self !== '' && String(data.origin || '') === this.self;

        var values = data.values || {};

        // Optimistic-mode version tracking. The base version follows the server
        // until the user has unsaved edits, then freezes so a stale save still
        // conflicts; the latest server version + values are always recorded for
        // conflict resolution ("keep mine" re-saves at the current version,
        // "take theirs" adopts these values).
        if (typeof data.version === 'number') {
            this.latestServerVersion = data.version;
            if (!this.dirtyDoc) {
                this.version = data.version;
            }
        }
        this.serverValues = values;

        for (var name in values) {
            if (!Object.prototype.hasOwnProperty.call(values, name)) {
                continue;
            }
            this.applyField(name, values[name], mine);
        }

        this.renderPresence(meta.presence || []);

        if (this.mode === 'form-lock') {
            this.applyFormLock(meta.locks || []);
        } else if (this.mode === 'field-lock') {
            this.applyFieldLocks(meta.locks || []);
        }
    };

    // -- lock modes: read the projected lock state and drive read-only UX -----

    /**
     * Reconcile the whole-form lock from the snapshot's `meta.locks`. When held by
     * someone else the form is read-only with a "locked by X" banner; when held by
     * this participant (or free) it is editable. A held-by-me lock keeps its
     * renewal heartbeat alive.
     */
    CollabForm.prototype.applyFormLock = function (locks) {
        var whole = null;
        for (var i = 0; i < locks.length; i++) {
            var l = locks[i];
            if (l && (l.field === null || l.field === undefined)) {
                whole = l;
                break;
            }
        }
        var holderId = whole ? String(whole.holderId || '') : '';
        this.lockHolderId = holderId || null;
        this.lockHolderLabel = whole ? String(whole.holderLabel || '') : null;

        var heldByMe = holderId !== '' && holderId === this.self;
        var lockedByOther = holderId !== '' && holderId !== this.self;

        if (heldByMe) {
            if (!this.iHoldLock) {
                this.iHoldLock = true;
                this.startLockHeartbeat();
            }
            this.setFieldsDisabled(false);
            this.renderLockBanner(null);
        } else if (lockedByOther) {
            this.iHoldLock = false;
            this.stopLockHeartbeat();
            this.setFieldsDisabled(true);
            this.renderLockBanner(this.lockHolderLabel || 'another participant');
        } else {
            // Free: editable; the next focus claims it.
            this.iHoldLock = false;
            this.stopLockHeartbeat();
            this.setFieldsDisabled(false);
            this.renderLockBanner(null);
        }
    };

    CollabForm.prototype.setFieldsDisabled = function (disabled) {
        var fields = Array.isArray(this.m.fields) ? this.m.fields : [];
        for (var i = 0; i < fields.length; i++) {
            var input = this.inputOf(this.fieldEl(String(fields[i])));
            if (input) {
                input.disabled = !!disabled;
            }
        }
    };

    CollabForm.prototype.renderLockBanner = function (holderLabel) {
        var host = this.root.querySelector('[data-ui-collab-lock]');
        if (!host) {
            return;
        }
        if (holderLabel) {
            host.textContent = '🔒 Locked by ' + holderLabel;
            host.removeAttribute('hidden');
            host.setAttribute('data-state', 'locked');
        } else {
            host.textContent = '';
            host.setAttribute('hidden', '');
            host.removeAttribute('data-state');
        }
    };

    CollabForm.prototype.acquireLock = function () {
        var ctx = this.m.events && this.m.events['lock.acquire'];
        if (!ctx || this.iHoldLock) {
            return;
        }
        var self = this;
        this.postEventAwait('lock.acquire', ctx, {}).then(function (res) {
            if (res && (res.status === 'accepted' || res.kind === 'ack')) {
                self.iHoldLock = true;
                self.startLockHeartbeat();
                self.setFieldsDisabled(false);
                self.renderLockBanner(null);
            } else if (res && res.reason === 'lock_unavailable') {
                // Lost the race — go read-only immediately (the snapshot will
                // fill in the holder's label).
                self.iHoldLock = false;
                self.setFieldsDisabled(true);
                self.renderLockBanner(self.lockHolderLabel || 'another participant');
            }
        });
    };

    CollabForm.prototype.releaseLock = function () {
        if (!this.iHoldLock) {
            return;
        }
        this.iHoldLock = false;
        this.stopLockHeartbeat();
        var ctx = this.m.events && this.m.events['lock.release'];
        if (ctx) {
            this.postEvent('lock.release', ctx, {});
        }
    };

    CollabForm.prototype.startLockHeartbeat = function () {
        if (this.lockHeartbeatTimer !== null) {
            return;
        }
        var ctx = this.m.events && this.m.events['lock.heartbeat'];
        if (!ctx) {
            return;
        }
        var self = this;
        // Renew well within the lock TTL (30s server-side) so a holder never
        // loses the lock while actively editing.
        this.lockHeartbeatTimer = setInterval(function () {
            self.postEventAwait('lock.heartbeat', ctx, {}).then(function (res) {
                if (res && res.reason === 'lock_lost') {
                    self.iHoldLock = false;
                    self.stopLockHeartbeat();
                    self.setFieldsDisabled(true);
                }
            });
        }, 10000);
    };

    CollabForm.prototype.stopLockHeartbeat = function () {
        if (this.lockHeartbeatTimer !== null) {
            clearInterval(this.lockHeartbeatTimer);
            this.lockHeartbeatTimer = null;
        }
    };

    // -- field-lock: per-field exclusive editing ----------------------------

    /**
     * Reconcile the per-field locks from the snapshot's `meta.locks`. A field held
     * by someone else is disabled and tagged `data-ui-collab-field-lock="<label>"`;
     * a field held by this participant (or free) is editable. Distinct fields can
     * be co-edited by distinct participants — only same-field collisions are
     * blocked.
     */
    CollabForm.prototype.applyFieldLocks = function (locks) {
        var holders = Object.create(null);
        for (var i = 0; i < locks.length; i++) {
            var l = locks[i];
            if (l && l.field) {
                holders[String(l.field)] = l;
            }
        }
        this.fieldLockHolders = holders;

        var fields = Array.isArray(this.m.fields) ? this.m.fields : [];
        for (var k = 0; k < fields.length; k++) {
            var name = String(fields[k]);
            var input = this.inputOf(this.fieldEl(name));
            if (!input) {
                continue;
            }
            var lock = holders[name];
            var holderId = lock ? String(lock.holderId || '') : '';
            if (holderId !== '' && holderId !== this.self) {
                input.disabled = true;
                input.setAttribute('data-ui-collab-field-lock', lock.holderLabel || 'someone');
                delete this.myFieldLocks[name];
            } else {
                input.disabled = false;
                input.removeAttribute('data-ui-collab-field-lock');
                if (holderId !== '' && holderId === this.self) {
                    this.myFieldLocks[name] = true;
                    this.ensureFieldHeartbeat();
                }
            }
        }
    };

    CollabForm.prototype.acquireFieldLock = function (name) {
        var ctx = this.m.events && this.m.events['lock.acquire'];
        if (!ctx || this.myFieldLocks[name]) {
            return;
        }
        var self = this;
        this.postEventAwait('lock.acquire', ctx, { field: name }).then(function (res) {
            if (res && (res.status === 'accepted' || res.kind === 'ack')) {
                self.myFieldLocks[name] = true;
                self.ensureFieldHeartbeat();
            } else if (res && res.reason === 'lock_unavailable') {
                var input = self.inputOf(self.fieldEl(name));
                if (input) {
                    input.disabled = true;
                }
            }
        });
    };

    CollabForm.prototype.releaseFieldLock = function (name) {
        if (!this.myFieldLocks[name]) {
            return;
        }
        delete this.myFieldLocks[name];
        var ctx = this.m.events && this.m.events['lock.release'];
        if (ctx) {
            this.postEvent('lock.release', ctx, { field: name });
        }
        if (this.countMyFieldLocks() === 0) {
            this.stopFieldHeartbeat();
        }
    };

    CollabForm.prototype.countMyFieldLocks = function () {
        var n = 0;
        for (var k in this.myFieldLocks) {
            if (Object.prototype.hasOwnProperty.call(this.myFieldLocks, k)) {
                n++;
            }
        }
        return n;
    };

    CollabForm.prototype.ensureFieldHeartbeat = function () {
        if (this.fieldHeartbeatTimer !== null) {
            return;
        }
        var ctx = this.m.events && this.m.events['lock.heartbeat'];
        if (!ctx) {
            return;
        }
        var self = this;
        this.fieldHeartbeatTimer = setInterval(function () {
            for (var name in self.myFieldLocks) {
                if (!Object.prototype.hasOwnProperty.call(self.myFieldLocks, name)) {
                    continue;
                }
                (function (field) {
                    self.postEventAwait('lock.heartbeat', ctx, { field: field }).then(function (res) {
                        if (res && res.reason === 'lock_lost') {
                            delete self.myFieldLocks[field];
                            var input = self.inputOf(self.fieldEl(field));
                            if (input) {
                                input.disabled = true;
                            }
                        }
                    });
                })(name);
            }
            if (self.countMyFieldLocks() === 0) {
                self.stopFieldHeartbeat();
            }
        }, 10000);
    };

    CollabForm.prototype.stopFieldHeartbeat = function () {
        if (this.fieldHeartbeatTimer !== null) {
            clearInterval(this.fieldHeartbeatTimer);
            this.fieldHeartbeatTimer = null;
        }
    };

    /** Release every field lock this participant holds (teardown). */
    CollabForm.prototype.releaseAllFieldLocks = function () {
        for (var name in this.myFieldLocks) {
            if (Object.prototype.hasOwnProperty.call(this.myFieldLocks, name)) {
                var ctx = this.m.events && this.m.events['lock.release'];
                if (ctx) {
                    this.postEvent('lock.release', ctx, { field: name });
                }
            }
        }
        this.myFieldLocks = Object.create(null);
        this.stopFieldHeartbeat();
    };

    CollabForm.prototype.applyField = function (name, value, mine) {
        var fieldEl = this.fieldEl(name);
        var input = this.inputOf(fieldEl);
        if (!input) {
            return;
        }
        // Guard (1): never clobber a field the user is editing right now, and
        // skip own echoes entirely.
        if (this.dirty[name] || input === document.activeElement) {
            return;
        }
        if (mine) {
            return;
        }
        // Guard (1b) — optimistic: the user works on their own copy until they
        // save or take-theirs, so an incoming snapshot must NEVER overwrite any
        // unsaved local edit (the version freeze handles conflict detection).
        if (this.mode === 'optimistic' && this.dirtyDoc) {
            return;
        }
        var next = value === null || value === undefined ? '' : String(value);
        if (input.value === next) {
            return; // no-op: avoids spurious input events / cursor jumps
        }
        input.value = next;
        // Let any local view logic react without re-triggering a collab emit
        // (the emit listener checks the `__collabApplying` flag).
        this.__applying = true;
        try {
            input.dispatchEvent(new Event('input', { bubbles: true }));
        } finally {
            this.__applying = false;
        }
    };

    CollabForm.prototype.renderPresence = function (roster) {
        var host = this.root.querySelector(PRESENCE_SELECTOR);
        if (!host) {
            return;
        }
        host.textContent = '';
        for (var i = 0; i < roster.length; i++) {
            var p = roster[i] || {};
            var chip = document.createElement('span');
            chip.className = 'ui-collab-chip';
            chip.setAttribute('data-participant-id', String(p.participantId || ''));
            if (this.self !== '' && String(p.participantId) === this.self) {
                chip.setAttribute('data-self', '1');
            }
            chip.textContent = String(p.label || p.participantId || 'Guest');
            host.appendChild(chip);
        }
    };

    CollabForm.prototype.onDocumentError = function (envelope) {
        // Optimistic-mode conflicts surface here as a typed error frame; expose
        // them on the status host so a page/demo can render a merge prompt.
        this.setStatus('error');
        var detail = envelope && envelope.error ? String(envelope.error) : 'collab_error';
        this.root.dispatchEvent(new CustomEvent('semitexa:collab:error', {
            bubbles: true,
            detail: { error: detail, envelope: envelope }
        }));
    };

    CollabForm.prototype.setStatus = function (state) {
        var host = this.root.querySelector(STATUS_SELECTOR);
        if (host) {
            host.setAttribute('data-state', state);
        }
    };

    // -- emitting local edits (mirrors event-runtime POST envelope) ---------

    CollabForm.prototype.wireLocalEdits = function () {
        var self = this;
        var fields = Array.isArray(this.m.fields) ? this.m.fields : [];
        for (var i = 0; i < fields.length; i++) {
            (function (name) {
                var input = self.inputOf(self.fieldEl(name));
                if (!input) {
                    return;
                }
                input.addEventListener('focus', function () {
                    self.dirty[name] = true;
                    // FormLock: claim the whole-form lock the moment this
                    // participant starts editing (the "first editor wins" rule).
                    if (self.mode === 'form-lock' && !self.iHoldLock) {
                        self.acquireLock();
                    } else if (self.mode === 'field-lock') {
                        // FieldLock: claim THIS field exclusively on focus.
                        self.acquireFieldLock(name);
                    }
                });
                input.addEventListener('blur', function () {
                    delete self.dirty[name];
                    // FormLock: release the lock once focus leaves the WHOLE form
                    // (not merely hops between its fields), so another participant
                    // can take over without waiting for the TTL to expire.
                    if (self.mode === 'form-lock' && self.iHoldLock) {
                        setTimeout(function () {
                            if (self.closed) {
                                return;
                            }
                            if (!self.root.contains(document.activeElement)) {
                                self.releaseLock();
                            }
                        }, 150);
                    } else if (self.mode === 'field-lock') {
                        // FieldLock: release this field immediately so another
                        // participant can take it (other fields stay co-editable).
                        self.releaseFieldLock(name);
                    }
                });
                input.addEventListener('input', function () {
                    if (self.__applying) {
                        return; // a remote apply, not a human keystroke
                    }
                    self.dirtyDoc = true;
                    if (self.mode === 'optimistic') {
                        // No live broadcast — the edit is committed on save, under
                        // the version guard. Surface that there are unsaved changes.
                        self.setSaveStatus('unsaved');
                        return;
                    }
                    self.emitFieldEdit(name, input.value);
                });
            })(String(fields[i]));
        }
    };

    CollabForm.prototype.emitFieldEdit = function (field, value) {
        var ctx = this.m.events && this.m.events['field.edit'];
        if (!ctx) {
            return;
        }
        this.postEvent('field.edit', ctx, { field: field, value: value });
    };

    CollabForm.prototype.emitPresencePing = function () {
        var ctx = this.m.events && this.m.events['presence.ping'];
        if (!ctx) {
            return;
        }
        this.postEvent('presence.ping', ctx, { role: 'editor' });
    };

    CollabForm.prototype.postEvent = function (semanticEvent, ctx, payload) {
        var body = {
            schemaVersion: 1,
            eventId: mintHexId('ui_evt_', 32),
            correlationId: mintHexId('ui_cor_', 32),
            semanticEvent: semanticEvent,
            signedContext: ctx,
            timestamp: nowIso(),
            payload: payload
        };
        try {
            fetch(this.m.eventUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
                keepalive: true
            }).catch(function () { /* best-effort; the next edit/heartbeat retries state */ });
        } catch (e) { /* noop */ }
    };

    // -- optimistic mode: save under the version guard ----------------------

    /** Like postEvent, but resolves with the parsed dispatch response (or null). */
    CollabForm.prototype.postEventAwait = function (semanticEvent, ctx, payload) {
        var body = {
            schemaVersion: 1,
            eventId: mintHexId('ui_evt_', 32),
            correlationId: mintHexId('ui_cor_', 32),
            semanticEvent: semanticEvent,
            signedContext: ctx,
            timestamp: nowIso(),
            payload: payload
        };
        try {
            return fetch(this.m.eventUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            }).then(function (r) {
                return r.json().catch(function () { return null; });
            }).catch(function () { return null; });
        } catch (e) {
            return Promise.resolve(null);
        }
    };

    CollabForm.prototype.collectValues = function () {
        var out = {};
        var fields = Array.isArray(this.m.fields) ? this.m.fields : [];
        for (var i = 0; i < fields.length; i++) {
            var name = String(fields[i]);
            var input = this.inputOf(this.fieldEl(name));
            out[name] = input ? input.value : '';
        }
        return out;
    };

    CollabForm.prototype.wireOptimisticSave = function () {
        var self = this;
        var btn = this.root.querySelector('[data-ui-collab-save]');
        if (!btn) {
            return;
        }
        btn.addEventListener('click', function (ev) {
            if (ev && typeof ev.preventDefault === 'function') {
                ev.preventDefault();
            }
            self.save();
        });
    };

    CollabForm.prototype.save = function () {
        var ctx = this.m.events && this.m.events['form.save'];
        if (!ctx) {
            return;
        }
        var self = this;
        this.clearConflict();
        this.setSaveStatus('saving');
        this.postEventAwait('form.save', ctx, {
            values: this.collectValues(),
            version: this.version | 0
        }).then(function (res) {
            if (res && (res.status === 'accepted' || res.kind === 'ack')) {
                // A successful save bumps the document by exactly one. Advance the
                // base version locally so a follow-up save is not falsely stale,
                // then mark the local copy committed; the re-projected snapshot
                // will confirm the authoritative version.
                self.dirtyDoc = false;
                self.version = (self.version | 0) + 1;
                self.setSaveStatus('saved');
            } else if (res && res.reason === 'form_draft_version_conflict') {
                self.renderConflict(res.message || 'The document changed since you opened it.');
            } else {
                self.setSaveStatus('error');
            }
        });
    };

    CollabForm.prototype.setSaveStatus = function (state) {
        var host = this.root.querySelector('[data-ui-collab-save-status]');
        if (!host) {
            return;
        }
        var label = state === 'saving' ? 'Saving…'
            : state === 'saved' ? 'Saved ✓'
            : state === 'unsaved' ? 'Unsaved changes'
            : state === 'error' ? 'Save failed'
            : '';
        host.textContent = label;
        host.setAttribute('data-state', state);
    };

    CollabForm.prototype.clearConflict = function () {
        var host = this.root.querySelector('[data-ui-collab-conflict]');
        if (!host) {
            return;
        }
        host.textContent = '';
        host.setAttribute('hidden', '');
        host.removeAttribute('data-state');
    };

    CollabForm.prototype.renderConflict = function (message) {
        var host = this.root.querySelector('[data-ui-collab-conflict]');
        if (!host) {
            this.setSaveStatus('error');
            return;
        }
        var self = this;
        host.textContent = '';
        host.removeAttribute('hidden');
        host.setAttribute('data-state', 'conflict');

        var msg = document.createElement('p');
        msg.setAttribute('data-ui-collab-conflict-message', '');
        msg.style.margin = '0 0 0.5rem';
        msg.textContent = message;
        host.appendChild(msg);

        var keepMine = document.createElement('button');
        keepMine.type = 'button';
        keepMine.setAttribute('data-ui-collab-keep-mine', '');
        keepMine.textContent = 'Keep mine';
        keepMine.addEventListener('click', function () {
            // Overwrite the newer document with the local values: re-save at the
            // server's current version so the guard passes.
            if (typeof self.latestServerVersion === 'number') {
                self.version = self.latestServerVersion;
            }
            self.save();
        });

        var takeTheirs = document.createElement('button');
        takeTheirs.type = 'button';
        takeTheirs.setAttribute('data-ui-collab-take-theirs', '');
        takeTheirs.textContent = 'Take theirs';
        takeTheirs.addEventListener('click', function () {
            self.discardLocalToServer();
        });

        host.appendChild(keepMine);
        host.appendChild(takeTheirs);
        this.setSaveStatus('error');
    };

    /** Take theirs: drop unsaved local edits and adopt the latest server snapshot. */
    CollabForm.prototype.discardLocalToServer = function () {
        this.dirtyDoc = false;
        if (typeof this.latestServerVersion === 'number') {
            this.version = this.latestServerVersion;
        }
        var values = this.serverValues || {};
        var fields = Array.isArray(this.m.fields) ? this.m.fields : [];
        for (var i = 0; i < fields.length; i++) {
            var name = String(fields[i]);
            var input = this.inputOf(this.fieldEl(name));
            if (input) {
                var v = values[name];
                input.value = (v === null || v === undefined) ? '' : String(v);
            }
        }
        this.clearConflict();
        this.setSaveStatus('saved');
    };

    CollabForm.prototype.startHeartbeat = function () {
        var self = this;
        this.emitPresencePing();
        this.heartbeatTimer = setInterval(function () {
            self.emitPresencePing();
        }, this.heartbeatMs);
    };

    CollabForm.prototype.destroy = function () {
        this.closed = true;
        // Release any held lock so a torn-down holder does not block the form (or a
        // field) until the TTL expires (the unsubscribe + release both ride the
        // still-open shared connection).
        this.releaseLock();
        this.releaseAllFieldLocks();
        if (this.sub) {
            // Hard unsubscribe over the shared connection so the server reaps this
            // subscription's record (a surviving KISS connection would otherwise
            // keep it registered — the orphaned-on-teardown failure mode).
            try { this.sub.unsubscribe(); } catch (e) { /* noop */ }
            this.sub = null;
        }
        this.closeSource();
        if (this.reconnectTimer !== null) {
            clearTimeout(this.reconnectTimer);
            this.reconnectTimer = null;
        }
        if (this.heartbeatTimer !== null) {
            clearInterval(this.heartbeatTimer);
            this.heartbeatTimer = null;
        }
    };

    // minimal CSS.escape fallback (attribute-value safe subset)
    function cssEscape(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(value);
        }
        return String(value).replace(/["\\\]]/g, '\\$&');
    }

    // ---- boot -------------------------------------------------------------

    function bootScript(scriptEl) {
        var manifest = parseManifest(scriptEl);
        if (!manifest) {
            return;
        }
        var rootEl = scriptEl.closest('[data-ui-component-instance-id]') || scriptEl.parentElement;
        if (!rootEl) {
            return;
        }
        var key = String(manifest.i || rootEl.getAttribute('data-ui-component-instance-id') || '');
        if (key !== '' && booted[key]) {
            return; // idempotent re-scan
        }
        var instance = new CollabForm(rootEl, manifest);
        if (key !== '') {
            booted[key] = instance;
        }
        instance.start();
    }

    function bootAll(root) {
        var scope = root || document;
        var scripts = scope.querySelectorAll(MANIFEST_SELECTOR);
        for (var i = 0; i < scripts.length; i++) {
            bootScript(scripts[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { bootAll(document); });
    } else {
        bootAll(document);
    }

    // Late-arriving (SSR-deferred) components announce themselves, exactly as
    // the grid runtime listens for.
    document.addEventListener('semitexa:component:rendered', function (ev) {
        bootAll((ev && ev.target) || document);
    });
    document.addEventListener('semitexa:block:rendered', function (ev) {
        bootAll((ev && ev.target) || document);
    });

    // Tear down (and unsubscribe) every booted collab form inside a removed node.
    function teardownWithin(node) {
        var roots = [];
        if (node.matches && node.matches('[data-ui-component-instance-id]')) {
            roots.push(node);
        }
        if (node.querySelectorAll) {
            var found = node.querySelectorAll('[data-ui-component-instance-id]');
            for (var i = 0; i < found.length; i++) {
                roots.push(found[i]);
            }
        }
        for (var k = 0; k < roots.length; k++) {
            var id = roots[k].getAttribute('data-ui-component-instance-id');
            if (id && booted[id]) {
                try { booted[id].destroy(); } catch (e) { /* noop */ }
                delete booted[id];
            }
        }
    }

    if (typeof MutationObserver === 'function') {
        new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                var added = mutations[i].addedNodes || [];
                for (var j = 0; j < added.length; j++) {
                    var node = added[j];
                    if (node.nodeType !== 1) {
                        continue;
                    }
                    if (node.matches && node.matches(MANIFEST_SELECTOR)) {
                        bootScript(node);
                    } else if (node.querySelectorAll) {
                        bootAll(node);
                    }
                }
                // A collab form removed from the DOM must unsubscribe so its
                // server-side record is reaped (the shared connection survives).
                var removed = mutations[i].removedNodes || [];
                for (var r = 0; r < removed.length; r++) {
                    if (removed[r].nodeType === 1) {
                        teardownWithin(removed[r]);
                    }
                }
            }
        }).observe(document.documentElement, { childList: true, subtree: true });
    }

    window.SemitexaUi = window.SemitexaUi || {};
    window.SemitexaUi.formCollab = { bootAll: bootAll };
})();
