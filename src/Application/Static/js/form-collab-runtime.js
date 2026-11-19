/**
 * Collaborative Form Data · Phase 3 (Shared mode) — form-collab-runtime.js
 *
 * The browser half of live collaborative forms. The document-feed sibling of
 * grid-runtime-v2.js: where the grid runtime subscribes a list route and
 * re-renders rows on `ui.collection.data`, this subscribes ONE collaborative
 * document (feed `platform-ui.form-doc`) and re-applies field values on
 * `ui.document.data`. Both runtimes ride the SAME transport —
 * core.openFeedChannel in ui-core.js (shared KISS subscribe, dedicated
 * EventSource degrade, stream-id adoption, backoff reconnect) — plus a
 * DOMContentLoaded + MutationObserver boot scan; this adds the form-specific
 * behaviour: apply remote field deltas, render the presence roster, and emit
 * each local edit back as a `field.edit` event on the canonical HUG (`POST /__semitexa_hug`)
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
 * documented fast-path. A field whose own edit is still on its way to the
 * server (in flight, or waiting behind one) counts as being edited too.
 *
 * EDIT ORDER: every `field.edit` carries the whole value and no sequence, and
 * the server keeps whichever arrives last. So a field has at most ONE edit in
 * flight; while it flies only the latest value is kept, and it goes out when
 * the one before it settles. An edit with no answer in 10 s is aborted and
 * counts as failed. A failed value is sent once more at once, then on up to
 * three heartbeats, then dropped. A lock is released only after the field's
 * last edit settled, so the edit is never refused for arriving behind the
 * release.
 *   Not covered, and only a sequence number from the server would: when the
 * page is hidden the latest value (and a release waiting for it) is sent at
 * once, beside an edit still in flight, and the two can arrive in either
 * order; and an edit aborted after 10 s that the server still processes can
 * land after the newer value sent behind it. A refusal (4xx: the session
 * expired, the lock was lost) is an answer: the value it refused is not sent
 * again.
 *
 * LOCKED FIELDS AND THE APP'S OWN POST: a field someone else holds is
 * `disabled`, and a browser leaves disabled controls out of a form's data, so
 * a save made meanwhile stored the field empty. When the field's form is
 * submitted, the controls THIS runtime disabled are enabled for the moment
 * the browser builds the form's data and disabled again right after; what the
 * data holds, and in which order, is the browser's doing alone. A control the
 * page disabled itself (or a disabled fieldset around it) is never touched.
 *   Not covered: data built with no submit event before it, that is
 * `form.submit()` and a `new FormData(form)` outside a submit listener.
 *
 * Manifest contract (emitted by the server `ui_collab_manifest()` helper as
 * `<script type="application/json" data-ui-collab-manifest>`):
 *   {
 *     v, i,                       schema version + render instance id
 *     scope, mode, self,          document scope, collaboration mode, my id
 *     feed, feedCtx,              the read feed's route name + its signed cfg token
 *     events,                     { "field.edit": ctx, "presence.ping": ctx } — posted to HUG
 *     fields, heartbeatMs         managed field names + presence cadence
 *     saved                       id of the save notice current at render ('' = none/unknown)
 *   }
 */
// ES module: the live-feed transport + CSRF plumbing arrive through the
// import map ('platform-ui/core' -> fingerprinted URL); the import graph
// guarantees the core is initialized before this executes.
import { openFeedChannel, mount, envelope, hug } from 'platform-ui/core';

(function () {
    'use strict';

    var SCHEMA_VERSION = 1;
    var MANIFEST_SELECTOR = 'script[type="application/json"][data-ui-collab-manifest]';
    var FIELD_SELECTOR = '[data-ui-field-name]';
    var PRESENCE_SELECTOR = '[data-ui-collab-presence]';
    var STATUS_SELECTOR = '[data-ui-collab-status]';
    var DEFAULT_HEARTBEAT_MS = 15000;
    // A field.edit with no answer after this long is aborted and counts as failed.
    var EDIT_TIMEOUT_MS = 10000;
    // Heartbeats a value whose send keeps failing is tried on before it is dropped.
    var EDIT_HEARTBEAT_RETRIES = 3;
    // The longest a lock release waits for edits in flight (two timeouts and a margin).
    var RELEASE_WAIT_MS = 25000;
    // A field's lock.acquire with no answer after this long is aborted: the
    // next focus or change of the field asks again.
    var ACQUIRE_TIMEOUT_MS = 10000;

    /** Booted instances, keyed by render instance id, so a re-scan is idempotent. */
    var booted = Object.create(null);

    // ---- small helpers ----------------------------------------------------

    function parseManifest(scriptEl) {
        var raw = scriptEl.textContent || '';
        var data;
        try {
            data = JSON.parse(raw);
        } catch (e) {
            return null;
        }
        if (!data || data.v !== SCHEMA_VERSION || typeof data.feed !== 'string' || typeof data.feedCtx !== 'string') {
            return null;
        }
        return data;
    }

    /**
     * The inside of a prompt whose host is the alert primitive: the body the
     * alert CSS lays out, a title, the message, and a row for the buttons.
     */
    function promptBody(host, title, message, messageAttr) {
        var body = document.createElement('div');
        body.setAttribute('data-alert-body', '');
        var heading = document.createElement('div');
        heading.setAttribute('data-alert-title', '');
        heading.textContent = title;
        body.appendChild(heading);
        var text = document.createElement('div');
        text.setAttribute(messageAttr, '');
        text.textContent = message;
        body.appendChild(text);
        var actions = document.createElement('div');
        actions.setAttribute('sx-layout', 'cluster');
        actions.setAttribute('sx-gap', '2');
        actions.style.marginTop = '0.5rem';
        body.appendChild(actions);
        host.appendChild(body);
        return { actions: actions };
    }

    /** A button of the package's button primitive, small and outlined. */
    function promptButton(attr, label, onClick) {
        var b = document.createElement('button');
        b.type = 'button';
        b.setAttribute(attr, '');
        b.setAttribute('ui', 'button');
        b.setAttribute('ui-size', 'sm');
        b.setAttribute('ui-variant', 'outline');
        b.setAttribute('ui-tone', 'neutral');
        b.textContent = label;
        b.addEventListener('click', onClick);
        return b;
    }

    // ---- one collaborative form instance ----------------------------------

    function CollabForm(rootEl, manifest) {
        this.root = rootEl;
        this.m = manifest;
        this.channel = null;
        this.streamId = null;
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
        // Which draft the versions above count (see applySnapshot), and the
        // one the unsaved optimistic edits are based on: frozen with `version`,
        // sent with the save, checked by the server.
        this.epoch = '';
        this.baseEpoch = '';
        // What the server said a retry must be based on, with a conflict.
        this.conflictBase = null;
        // "The record was saved" notices (meta.saved). The page was rendered
        // with the record as of the notice the manifest names, so that one is
        // never news; without a name in the manifest (none yet, or the cache
        // could not say) the first frame's notice is taken as the starting
        // point too, since nothing shows it is newer than the page.
        this.savedId = typeof manifest.saved === 'string' ? manifest.saved : '';
        this.savedKnown = this.savedId !== '';
        this.savedValues = Object.create(null);
        // The draft a save notice arrived with, still undeleted: {epoch, version}.
        this.dying = null;
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
        // Lock-mode edits typed before the async lock.acquire ack lands are
        // queued here (by field name) and flushed once the lock is held, so a
        // keystroke during the acquire round-trip is never silently dropped.
        this.pendingEdit = Object.create(null);
        // FieldLock: the fields whose lock.acquire has no answer yet, so a
        // second change of the field does not ask again. Each is the token of
        // that one attempt: an answer arriving after the attempt ended (its
        // deadline passed) acts for nothing, least of all for a newer attempt.
        this.fieldAcquiring = Object.create(null);
        // field.edit order (see EDIT ORDER above), by field name:
        // {inFlight, sent, waiting, value, retried, beats}. `waiting` with no
        // edit in flight is a value whose send failed twice: the next edit,
        // the presence heartbeat (EDIT_HEARTBEAT_RETRIES times), a lock
        // release or the page being hidden sends it.
        this.edits = Object.create(null);
        this.editWaiters = [];
        // Lock releases asked for while an edit was still in flight.
        // Each is the token of the one release asked for (null / absent: none),
        // so a release that focus cancelled never acts for a later one.
        this.lockReleasing = null;
        this.fieldReleasing = Object.create(null);
        // The fields THIS runtime disabled for a lock, as opposed to a control
        // the page disabled for its own reasons; the ones enabled for the
        // form submit under way; and that submit.
        this.lockedInputs = Object.create(null);
        this.submitUnlocked = [];
        this.submitEvent = null;
        // The fields the app's submitted form carried, with no edit typed
        // into them since: the post carries their values, and the app ends
        // the draft. A field of another form, or one typed into after the
        // submit, is not in that post.
        this.postedFields = Object.create(null);
        this.formListeners = [];
        this.pageHideListener = null;
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
        this.wireLockedFieldsIntoSubmit();
        this.wirePageHide();
        this.subscribe();
        this.startHeartbeat();
        if (this.mode === 'optimistic') {
            this.wireOptimisticSave();
        }
    };

    // -- transport: openFeedChannel (platform-ui/core) — the document feed on
    //    the page's KISS stream, subscribed through HUG by name ---------------

    CollabForm.prototype.subscribe = function () {
        if (this.closed) {
            return;
        }
        var self = this;
        this.channel = openFeedChannel({
            feed: this.m.feed,
            params: { ctx: this.m.feedCtx },
            dataEvent: 'ui.document.data',
            errorEvent: 'ui.document.error',
            onData: function (envelope) { self.applySnapshot(envelope); },
            onError: function (envelope) { self.onDocumentError(envelope || {}); },
            onStreamId: function (id) { self.streamId = id; },
            onStatus: function (status) { self.setStatus(status); },
            // Collaboration is live or nothing: a page without a KISS session
            // says so instead of showing a stale document as shared.
            onPull: function () { self.setStatus('offline'); }
        });
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

        // A draft ENDS when the record is saved (the app discards it and
        // leaves a notice, meta.saved) or when it is abandoned (expiry); the
        // next one starts at version 1 again. Three signs, one reaction:
        //   - a notice this page has not seen, newer than the page itself;
        //   - another epoch (the epoch names the draft) after a draft was seen;
        //   - a version going DOWN, for a store that sends no epoch.
        var hasVersion = typeof data.version === 'number';
        var epoch = typeof data.epoch === 'string' ? data.epoch : '';
        var draftEnded = hasVersion
            && ((this.epoch !== '' && epoch !== this.epoch) || data.version < this.latestServerVersion);
        var saved = meta.saved && typeof meta.saved.id === 'string' && meta.saved.id !== '' ? meta.saved : null;
        var savedNow = false;
        if (saved && saved.id !== this.savedId) {
            this.savedId = saved.id;
            savedNow = this.savedKnown;
        }
        this.savedKnown = true;
        // The notice is published before the draft is deleted, so a frame can
        // carry the notice and the dying draft together. Its values are what
        // the save replaced: they are not applied, in that frame or in any
        // repeat of it, and its end is the same event, not a second one. A
        // draft that moved on instead (another version: the delete failed and
        // someone edited) is a live draft again.
        var dyingNow = savedNow && !draftEnded && hasVersion && epoch !== '';
        var stillDying = !savedNow && this.dying !== null && hasVersion
            && epoch === this.dying.epoch && data.version === this.dying.version;
        var endOfDying = this.dying !== null
            && this.epoch === this.dying.epoch && this.latestServerVersion === this.dying.version;
        try {
            if (savedNow) {
                this.onRecordSaved(saved.values && typeof saved.values === 'object' ? saved.values : null);
            } else if (draftEnded && !endOfDying) {
                this.onRecordSaved(null);
            }
        } finally {
            if (dyingNow) {
                this.dying = { epoch: epoch, version: data.version };
            } else if (!stillDying) {
                this.dying = null;
            }
        }

        // Optimistic-mode version tracking. The base (version + epoch) follows
        // the server until the user has unsaved edits, then freezes so a stale
        // save still conflicts; the latest server version + values are always
        // recorded for conflict resolution ("keep mine" re-saves on the
        // current base, "take theirs" adopts these values).
        if (hasVersion) {
            this.epoch = epoch;
            this.latestServerVersion = data.version;
            if (!this.dirtyDoc) {
                this.version = data.version;
                this.baseEpoch = epoch;
            }
        }
        this.serverValues = values;

        if (!dyingNow && !stillDying) {
            for (var name in values) {
                if (!Object.prototype.hasOwnProperty.call(values, name)) {
                    continue;
                }
                this.applyField(name, values[name], mine);
            }
        }

        this.renderPresence(meta.presence || []);

        if (this.mode === 'form-lock') {
            this.applyFormLock(meta.locks || []);
        } else if (this.mode === 'field-lock') {
            this.applyFieldLocks(meta.locks || []);
        }
    };

    // -- the record was saved elsewhere --------------------------------------

    /**
     * The draft ended. With the stored values (`values`, from the app's
     * discard) each field named in them is set to what was stored. Without,
     * nothing here can say what the record now holds: the inputs stay as they
     * are, as in any form whose record changed under it, and the page is told
     * (`semitexa:collab:reset`, `detail.rebased`) so it can do more.
     *
     * Unsaved Optimistic edits are left alone either way: they were based on
     * a draft that is gone, the save names that draft (baseEpoch), and the
     * server answers it with the conflict prompt.
     */
    CollabForm.prototype.onRecordSaved = function (values) {
        // Every target is found before any input moves: a managed field the
        // app named and this page has no input for means the page and the
        // save disagree about the form, and half a re-base reported as done
        // is worse than none. Names that are not managed fields are not
        // targets (an app may pass the whole record).
        var managed = Array.isArray(this.m.fields) ? this.m.fields.map(String) : [];
        var targets = [];
        if (values) {
            for (var name in values) {
                if (!Object.prototype.hasOwnProperty.call(values, name) || managed.indexOf(name) === -1) {
                    continue;
                }
                if (!this.inputOf(this.fieldEl(name))) {
                    values = null;
                    break;
                }
                targets.push(name);
            }
        }
        if (values) {
            this.savedValues = values;
            for (var i = 0; i < targets.length; i++) {
                this.rebaseField(targets[i], values[targets[i]]);
            }
        } else {
            // What an earlier save stored says nothing about this one.
            this.savedValues = Object.create(null);
        }
        this.root.dispatchEvent(new CustomEvent('semitexa:collab:reset', {
            bubbles: true,
            detail: { rebased: !!values }
        }));
    };

    CollabForm.prototype.rebaseField = function (name, value) {
        var input = this.inputOf(this.fieldEl(name));
        if (!input || (this.mode === 'optimistic' && this.dirtyDoc)) {
            return;
        }
        var next = value === null || value === undefined ? '' : String(value);
        if (this.dirty[name] || input === document.activeElement || this.editBusy(name) || this.editQueued(name)) {
            // Being typed in right now (or its edit is still on the way).
            // What it holds went into the draft that just ended: send it again, so it is an edit everyone sees and
            // not text that only this tab still has.
            if (input.value !== next) {
                this.relayEdit(name, input.value);
            }
            return;
        }
        if (input.value === next) {
            return;
        }
        input.value = next;
        this.__applying = true;
        try {
            input.dispatchEvent(new Event('input', { bubbles: true }));
        } finally {
            this.__applying = false;
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
            // A change made without focus that was waiting for this
            // participant's lock (see relayEdit) cannot be sent any more, and
            // would keep the holder's text out of the input (see editQueued).
            var queued = [];
            for (var q in this.pendingEdit) {
                if (Object.prototype.hasOwnProperty.call(this.pendingEdit, q) && !this.hasFocus(q)) {
                    queued.push(q);
                }
            }
            for (var j = 0; j < queued.length; j++) {
                this.refuseEdit(queued[j]);
            }
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
                this.setInputLocked(String(fields[i]), input, !!disabled);
            }
        }
    };

    /** Disable (or free) one managed input, remembering that the lock did it. */
    CollabForm.prototype.setInputLocked = function (name, input, locked) {
        if (locked) {
            // Already disabled and not by a lock: the page's own, left alone.
            if (!input.disabled) {
                input.disabled = true;
                this.lockedInputs[name] = true;
            }
        } else if (this.lockedInputs[name]) {
            input.disabled = false;
            delete this.lockedInputs[name];
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
                // Lock now held — flush any keystrokes typed during the acquire.
                self.flushAllPendingEdits();
            } else if (res && res.reason === 'lock_unavailable') {
                // Lost the race — go read-only immediately (the snapshot will
                // fill in the holder's label). Drop queued edits we can't write.
                self.iHoldLock = false;
                self.pendingEdit = Object.create(null);
                self.setFieldsDisabled(true);
                self.renderLockBanner(self.lockHolderLabel || 'another participant');
            }
        });
    };

    /**
     * Release the whole-form lock, after every edit in flight settled: an
     * edit that arrived behind the release would be refused, and the last
     * thing typed before leaving the form would be lost. A value whose send
     * failed gets one more try first. Focus coming back before then keeps the
     * lock (see the focus listener).
     */
    CollabForm.prototype.releaseLock = function () {
        if (!this.iHoldLock) {
            return;
        }
        var token = this.lockReleasing = {};
        var self = this;
        this.retryUnsentEdits(null, false);
        this.afterEdits(null, function () {
            if (self.lockReleasing === token) {
                self.finishLockRelease();
            }
        });
    };

    CollabForm.prototype.finishLockRelease = function () {
        this.lockReleasing = null;
        if (!this.iHoldLock) {
            return;
        }
        this.dropUnsentEdits(null);
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
                this.setInputLocked(name, input, true);
                input.setAttribute('data-ui-collab-field-lock', lock.holderLabel || 'someone');
                delete this.myFieldLocks[name];
            } else {
                this.setInputLocked(name, input, false);
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
        if (!ctx || this.myFieldLocks[name] || this.fieldAcquiring[name]) {
            return;
        }
        var self = this;
        var token = this.fieldAcquiring[name] = {};
        var timer = null;
        // One stalled answer would otherwise keep the marker, and with it
        // every later focus and change of the field, until the page reloads.
        var abort = typeof AbortController === 'function' ? new AbortController() : null;
        var answered = function (res) {
            if (self.fieldAcquiring[name] !== token) {
                return; // this attempt ended; the field's state is not its to set
            }
            delete self.fieldAcquiring[name];
            if (timer !== null) {
                clearTimeout(timer);
                timer = null;
            }
            if (res && (res.status === 'accepted' || res.kind === 'ack')) {
                self.myFieldLocks[name] = true;
                self.ensureFieldHeartbeat();
                // Field lock now held — flush any keystrokes typed during acquire.
                self.flushPendingEdit(name);
                // Nobody is in the field: the lock was taken for a change made
                // without focus (see relayEdit), or focus left before this
                // answer. Released as blur releases it, after the edit settled.
                if (!self.hasFocus(name)) {
                    self.releaseFieldLock(name);
                }
            } else if (res && res.reason === 'lock_unavailable') {
                var input = self.inputOf(self.fieldEl(name));
                if (input) {
                    self.setInputLocked(name, input, true);
                }
                if (self.hasFocus(name)) {
                    delete self.pendingEdit[name]; // typed text stays, as before
                } else {
                    self.refuseEdit(name);
                }
            } else {
                // No answer (network): a change made without focus has no
                // later focus to send it. A focused field keeps what it queued.
                if (!self.hasFocus(name)) {
                    delete self.pendingEdit[name];
                }
            }
        };
        timer = setTimeout(function () {
            timer = null;
            if (abort) {
                try { abort.abort(); } catch (e) { /* noop */ }
            }
            answered(null);
        }, ACQUIRE_TIMEOUT_MS);
        var request;
        try {
            request = hug(
                envelope({ semanticEvent: 'lock.acquire', signedContext: ctx, payload: { field: name } }),
                abort ? { signal: abort.signal } : {}
            );
        } catch (e) {
            answered(null);
            return;
        }
        request.then(
            function (r) { answered(r ? r.data : null); },
            function () { answered(null); }
        );
    };

    /**
     * A change of `name` is waiting for a lock: queued (pendingEdit), or its
     * lock.acquire is on the way. The input holds that change and no snapshot
     * may replace it, focused or not.
     */
    CollabForm.prototype.editQueued = function (name) {
        return !!this.fieldAcquiring[name] || Object.prototype.hasOwnProperty.call(this.pendingEdit, name);
    };

    /** A person is in the field: focus took, or is taking, its lock. */
    CollabForm.prototype.hasFocus = function (name) {
        var input = this.inputOf(this.fieldEl(name));
        return !!this.dirty[name] || (!!input && input === document.activeElement);
    };

    /** Another participant holds `name` (FieldLock) or the form (FormLock). */
    CollabForm.prototype.heldByOther = function (name) {
        if (this.lockedInputs[name]) {
            return true;
        }
        if (this.mode === 'form-lock') {
            return this.lockHolderId !== null && this.lockHolderId !== this.self;
        }
        var lock = this.fieldLockHolders[name];
        var holderId = lock ? String(lock.holderId || '') : '';
        return holderId !== '' && holderId !== this.self;
    };

    /**
     * A change of a field another participant holds is not sent, now or
     * later, and is not shown: the input goes back to the draft's value when
     * the draft has one. Left in the input it would be in the app's form post
     * (see LOCKED FIELDS) and replace what the holder typed.
     */
    CollabForm.prototype.refuseEdit = function (name) {
        delete this.pendingEdit[name];
        var input = this.inputOf(this.fieldEl(name));
        if (!input || !Object.prototype.hasOwnProperty.call(this.serverValues, name)) {
            return;
        }
        var value = this.serverValues[name];
        var next = value === null || value === undefined ? '' : String(value);
        if (input.value === next) {
            return;
        }
        input.value = next;
        this.__applying = true;
        try {
            input.dispatchEvent(new Event('input', { bubbles: true }));
        } finally {
            this.__applying = false;
        }
    };

    /** Release one field, after its last edit settled (see releaseLock). */
    CollabForm.prototype.releaseFieldLock = function (name) {
        if (!this.myFieldLocks[name]) {
            return;
        }
        var token = this.fieldReleasing[name] = {};
        var self = this;
        this.retryUnsentEdits([name], false);
        this.afterEdits([name], function () {
            if (self.fieldReleasing[name] === token) {
                self.finishFieldRelease(name);
            }
        });
    };

    CollabForm.prototype.finishFieldRelease = function (name) {
        delete this.fieldReleasing[name];
        if (!this.myFieldLocks[name]) {
            return;
        }
        this.dropUnsentEdits([name]);
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
                                self.setInputLocked(field, input, true);
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
        var names = [];
        for (var name in this.myFieldLocks) {
            if (Object.prototype.hasOwnProperty.call(this.myFieldLocks, name)) {
                names.push(name);
            }
        }
        for (var i = 0; i < names.length; i++) {
            this.releaseFieldLock(names[i]);
        }
        if (this.countMyFieldLocks() === 0) {
            this.stopFieldHeartbeat();
        }
    };

    CollabForm.prototype.applyField = function (name, value, mine) {
        var fieldEl = this.fieldEl(name);
        var input = this.inputOf(fieldEl);
        if (!input) {
            return;
        }
        // Guard (1): never clobber a field the user is editing right now, and
        // skip own echoes entirely. A field whose edit has not reached the
        // server yet is still being edited: the frame holds an older value,
        // and the echo of the edit itself will be skipped as `mine`.
        // So is a change waiting for its lock (see editQueued): the grant
        // sends what the input holds, and that must be the change.
        if (this.dirty[name] || input === document.activeElement || this.editBusy(name) || this.editQueued(name)) {
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
                    // Back before a release waiting for its last edit went
                    // out: the lock is kept.
                    self.lockReleasing = null;
                    delete self.fieldReleasing[name];
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
                    self.relayEdit(name, input.value);
                });
            })(String(fields[i]));
        }
    };

    /**
     * Send a live edit of `name`, or queue it. Lock modes: focus kicked off an
     * async lock.acquire, and until the ack lands (iHoldLock / myFieldLocks)
     * the server would reject a field.edit and postEvent() swallows the error,
     * losing the keystroke. The acquire ack flushes the current value.
     *
     * A CHANGE WITHOUT FOCUS (a page script that fills the field and
     * dispatches `input`, a select chosen by a script) has no focus to take
     * the lock for it:
     *  - a field another participant holds (either lock mode): refused, see
     *    refuseEdit;
     *  - FieldLock, a free field: the change takes the field's lock itself,
     *    and the acquire ack sends it and releases the lock once the edit
     *    settled (see acquireFieldLock);
     *  - FormLock, a free form: queued, as before. It goes out when this
     *    participant next takes the form's lock. A script does not take the
     *    whole form: that would make every co-editor's form read-only and
     *    refuse the one who had just focused a field.
     */
    CollabForm.prototype.relayEdit = function (name, value) {
        var locked = (this.mode === 'form-lock' && !this.iHoldLock)
            || (this.mode === 'field-lock' && !this.myFieldLocks[name]);
        if (!locked) {
            this.emitFieldEdit(name, value);
            return;
        }
        var focused = this.hasFocus(name);
        if (!focused && this.heldByOther(name)) {
            this.refuseEdit(name);
            return;
        }
        this.pendingEdit[name] = value;
        if (this.mode === 'field-lock' && !focused) {
            this.acquireFieldLock(name);
        }
    };

    // Flush a queued lock-mode edit for `name`, sending the CURRENT input value
    // (so any keystrokes during the acquire round-trip are captured, not the
    // possibly-stale value at queue time).
    CollabForm.prototype.flushPendingEdit = function (name) {
        if (!Object.prototype.hasOwnProperty.call(this.pendingEdit, name)) {
            return;
        }
        delete this.pendingEdit[name];
        var input = this.inputOf(this.fieldEl(name));
        if (input) {
            this.emitFieldEdit(name, input.value);
        }
    };

    CollabForm.prototype.flushAllPendingEdits = function () {
        var names = [];
        for (var k in this.pendingEdit) {
            if (Object.prototype.hasOwnProperty.call(this.pendingEdit, k)) {
                names.push(k);
            }
        }
        for (var i = 0; i < names.length; i++) {
            this.flushPendingEdit(names[i]);
        }
    };

    /**
     * Queue `value` as the latest edit of `field` and send it unless an edit
     * of that field is in flight; then it goes out when that one settles.
     */
    CollabForm.prototype.emitFieldEdit = function (field, value) {
        if (!(this.m.events && this.m.events['field.edit'])) {
            return;
        }
        var q = this.edits[field];
        if (!q) {
            q = this.edits[field] = { inFlight: false, sent: null, waiting: false, value: null, retried: false, beats: 0 };
        }
        q.waiting = true;
        q.value = value;
        q.retried = false;
        q.beats = 0;
        delete this.postedFields[field]; // typed after the submit: not in that post
        this.sendNextEdit(field);
    };

    CollabForm.prototype.sendNextEdit = function (field) {
        var q = this.edits[field];
        var ctx = this.m.events && this.m.events['field.edit'];
        if (!q || !ctx || q.inFlight || !q.waiting) {
            return;
        }
        var self = this;
        var value = q.value;
        var timer = null;
        var over = false;
        q.waiting = false;
        q.inFlight = true;
        q.sent = value;
        var settled = function (failed) {
            if (over) {
                return; // the timeout and the aborted request both report
            }
            over = true;
            if (timer !== null) {
                clearTimeout(timer);
            }
            q.inFlight = false;
            if (failed && !q.waiting) {
                // Nothing newer was typed, so this is still the latest value
                // and the server does not have it: once more now, and after
                // that it waits (see `edits`).
                q.waiting = true;
                q.value = value;
                if (q.retried) {
                    self.editsSettled();
                    return;
                }
                q.retried = true;
            } else {
                q.retried = false;
            }
            if (q.waiting) {
                self.sendNextEdit(field);
            } else {
                self.editsSettled();
            }
        };
        // No keepalive: a browser refuses a keepalive body past its 64 KiB
        // quota at once, every time. The page being hidden sends with it.
        var abort = typeof AbortController === 'function' ? new AbortController() : null;
        // The status is the answer; the body is not used. Waiting for it
        // would turn a refusal whose body stalls into a timeout and a retry.
        var options = { onResponse: function (resp) { settled(resp.ok === false && resp.status >= 500); } };
        if (abort) {
            options.signal = abort.signal;
        }
        var request;
        try {
            request = hug(
                envelope({ semanticEvent: 'field.edit', signedContext: ctx, payload: { field: field, value: value } }),
                options
            );
        } catch (e) {
            settled(true);
            return;
        }
        // Failed = the server did not answer (network, EDIT_TIMEOUT_MS) or
        // broke (5xx). A refusal (4xx: the field is locked, unknown, the
        // session expired) is an answer.
        request.then(
            function (r) { settled(!!r && r.ok === false && r.status >= 500); },
            function () { settled(true); }
        );
        // One stalled request would otherwise hold the field, its lock and
        // everything typed after it for as long as the connection hangs.
        timer = setTimeout(function () {
            timer = null;
            if (abort) {
                try { abort.abort(); } catch (e) { /* noop */ }
            }
            settled(true);
        }, EDIT_TIMEOUT_MS);
    };

    /** An edit of `name` is in flight or waiting to be sent. */
    CollabForm.prototype.editBusy = function (name) {
        var q = this.edits[name];
        return !!q && (q.inFlight || q.waiting);
    };

    /**
     * Run `fn` once no edit of `names` (null: any field) is in flight, and
     * after RELEASE_WAIT_MS whatever is. The deadline is this call's own: `fn`
     * decides whether what it was asked for is still wanted (a release token).
     */
    CollabForm.prototype.afterEdits = function (names, fn) {
        if (!this.editsInFlight(names)) {
            fn();
            return;
        }
        var self = this;
        var waiter = { names: names, fn: fn };
        this.editWaiters.push(waiter);
        setTimeout(function () {
            var at = self.editWaiters.indexOf(waiter);
            if (at !== -1) {
                self.editWaiters.splice(at, 1);
                fn();
            }
        }, RELEASE_WAIT_MS);
    };

    CollabForm.prototype.editsInFlight = function (names) {
        for (var name in this.edits) {
            if (this.edits[name].inFlight && (names === null || names.indexOf(name) !== -1)) {
                return true;
            }
        }
        return false;
    };

    CollabForm.prototype.editsSettled = function () {
        var waiters = this.editWaiters;
        this.editWaiters = [];
        for (var i = 0; i < waiters.length; i++) {
            if (this.editsInFlight(waiters[i].names)) {
                this.editWaiters.push(waiters[i]);
            } else {
                waiters[i].fn();
            }
        }
    };

    /** Forget values that never went out, for fields about to lose their lock. */
    CollabForm.prototype.dropUnsentEdits = function (names) {
        for (var name in this.edits) {
            if (!this.edits[name].inFlight && (names === null || names.indexOf(name) !== -1)) {
                this.edits[name].waiting = false;
            }
        }
    };

    /**
     * Send again every value of `names` (null: any field) whose send failed.
     * `beat`: the heartbeat's retry, which gives up after
     * EDIT_HEARTBEAT_RETRIES; a server that never answers would otherwise be
     * asked for ever and the field would stay "being edited", closed to what
     * the others type.
     */
    CollabForm.prototype.retryUnsentEdits = function (names, beat) {
        for (var name in this.edits) {
            var q = this.edits[name];
            if (!q.waiting || q.inFlight || (names !== null && names.indexOf(name) === -1)) {
                continue;
            }
            var lockGone = (this.mode === 'form-lock' && !this.iHoldLock)
                || (this.mode === 'field-lock' && !this.myFieldLocks[name]);
            if (lockGone || (beat && q.beats >= EDIT_HEARTBEAT_RETRIES)) {
                q.waiting = false; // refused for sure, or tried long enough
                continue;
            }
            if (beat) {
                q.beats++;
            }
            this.sendNextEdit(name);
        }
    };

    /**
     * The page is going away (a navigation, a closed tab) and nothing will be
     * here when an edit in flight settles. Sent now, with keepalive: the
     * latest value of every field that has one waiting or in flight (a
     * request without keepalive dies with the page), then the lock releases
     * that were waiting for those edits.
     *   After the app's own form post the values of the fields that post
     * carried are NOT sent: the post carries them and the app ends the
     * draft, and an edit arriving behind that would start a new draft
     * holding a value the save already replaced. A field the post did not
     * carry (another form's, or one typed into since) is sent as usual.
     */
    CollabForm.prototype.wirePageHide = function () {
        if (typeof window.addEventListener !== 'function') {
            return;
        }
        var self = this;
        this.pageHideListener = function () {
            var ctx = self.m.events && self.m.events['field.edit'];
            for (var name in self.edits) {
                var q = self.edits[name];
                if (ctx && !self.postedFields[name] && (q.waiting || q.inFlight)) {
                    self.postEvent('field.edit', ctx, { field: name, value: q.waiting ? q.value : q.sent });
                }
                q.waiting = false;
            }
            var releasing = [];
            for (var field in self.fieldReleasing) {
                releasing.push(field);
            }
            for (var i = 0; i < releasing.length; i++) {
                self.finishFieldRelease(releasing[i]);
            }
            if (self.lockReleasing) {
                self.finishLockRelease();
            }
        };
        window.addEventListener('pagehide', this.pageHideListener);
    };

    // -- locked fields in the app's own form post ----------------------------

    CollabForm.prototype.wireLockedFieldsIntoSubmit = function () {
        var self = this;
        var fields = Array.isArray(this.m.fields) ? this.m.fields : [];
        var forms = [];
        for (var i = 0; i < fields.length; i++) {
            var input = this.inputOf(this.fieldEl(String(fields[i])));
            var form = input ? input.form : null;
            if (form && typeof form.addEventListener === 'function' && forms.indexOf(form) === -1) {
                forms.push(form);
            }
        }
        forms.forEach(function (form) {
            var onSubmit = function (ev) { self.unlockForSubmit(form, ev); };
            // The browser fires `formdata` once it has read the controls. One
            // fired while the submit event is still being dispatched is the
            // app's own `new FormData(form)`; the browser's list comes later.
            var onFormData = function () {
                if (!self.submitEvent || !self.submitEvent.eventPhase) {
                    self.relockAfterSubmit();
                }
            };
            form.addEventListener('submit', onSubmit, true);
            form.addEventListener('formdata', onFormData);
            self.formListeners.push({ form: form, type: 'submit', listener: onSubmit, capture: true });
            self.formListeners.push({ form: form, type: 'formdata', listener: onFormData, capture: false });
        });
    };

    /**
     * `form` is being submitted: enable the fields a lock disabled, for the
     * browser to read. They are disabled again when it has (`formdata`), and
     * in the next task whatever happened (a cancelled submit, an app that
     * posts the form itself).
     */
    CollabForm.prototype.unlockForSubmit = function (form, ev) {
        var self = this;
        var posted = Object.create(null);
        this.postedFields = posted;
        this.submitEvent = ev;
        var fields = Array.isArray(this.m.fields) ? this.m.fields : [];
        for (var i = 0; i < fields.length; i++) {
            var name = String(fields[i]);
            var input = this.inputOf(this.fieldEl(name));
            if (input && input.form === form) {
                posted[name] = true;
            }
            if (input && this.lockedInputs[name] && input.disabled && input.form === form) {
                input.disabled = false;
                this.submitUnlocked.push({ name: name, input: input });
            }
        }
        setTimeout(function () {
            self.relockAfterSubmit();
            if (self.submitEvent === ev) {
                self.submitEvent = null;
            }
            if (ev && ev.defaultPrevented && self.postedFields === posted) {
                self.postedFields = Object.create(null); // nothing was posted, or the page stays
            }
        }, 0);
    };

    CollabForm.prototype.relockAfterSubmit = function () {
        var unlocked = this.submitUnlocked;
        this.submitUnlocked = [];
        for (var i = 0; i < unlocked.length; i++) {
            if (this.lockedInputs[unlocked[i].name]) {
                unlocked[i].input.disabled = true;
            }
        }
    };

    CollabForm.prototype.emitPresencePing = function () {
        var ctx = this.m.events && this.m.events['presence.ping'];
        if (!ctx) {
            return;
        }
        this.postEvent('presence.ping', ctx, { role: 'editor' });
    };

    // Events go to HUG through the one client (core.envelope + core.hug).
    // Fire and forget, with keepalive so it outlives the page: for events a
    // later one of the same kind makes good (the next presence ping, the
    // lock's expiry) and for what is sent as the page is hidden. A field edit
    // otherwise goes through sendNextEdit, which sends a failed value again.
    CollabForm.prototype.postEvent = function (semanticEvent, ctx, payload) {
        try {
            hug(envelope({ semanticEvent: semanticEvent, signedContext: ctx, payload: payload }), { keepalive: true })
                .catch(function () { /* best-effort, see above */ });
        } catch (e) { /* noop */ }
    };

    // -- optimistic mode: save under the version guard ----------------------

    /** Like postEvent, but resolves with the parsed dispatch response (or null). */
    CollabForm.prototype.postEventAwait = function (semanticEvent, ctx, payload) {
        try {
            return hug(envelope({ semanticEvent: semanticEvent, signedContext: ctx, payload: payload }))
                .then(function (r) { return r.data; })
                .catch(function () { return null; });
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
            version: this.version | 0,
            epoch: this.baseEpoch
        }).then(function (res) {
            if (res && (res.status === 'accepted' || res.kind === 'ack')) {
                // The ack names the draft and version the next save is based
                // on (the first save of a document creates its draft). The
                // re-projected snapshot says the same, when the stream is up.
                self.dirtyDoc = false;
                var base = res.debug && res.debug.frontend ? res.debug.frontend.collab : null;
                if (base && typeof base.epoch === 'string' && typeof base.version === 'number') {
                    self.version = base.version;
                    self.baseEpoch = base.epoch;
                } else {
                    self.version = (self.version | 0) + 1;
                }
                self.conflictBase = null;
                self.setSaveStatus('saved');
            } else if (res && res.reason === 'form_draft_version_conflict') {
                var current = res.details || {};
                self.conflictBase = typeof current.currentEpoch === 'string' && typeof current.currentVersion === 'number'
                    ? { epoch: current.currentEpoch, version: current.currentVersion }
                    : null;
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

        var body = promptBody(host, 'Save conflict', message, 'data-ui-collab-conflict-message');

        var keepMine = promptButton('data-ui-collab-keep-mine', 'Keep mine', function () {
            // Overwrite the newer document with the local values: re-save on
            // the base the server named with the conflict (a client whose
            // stream is down has no frame to learn it from), else on the
            // latest frame's.
            if (self.conflictBase) {
                self.version = self.conflictBase.version;
                self.baseEpoch = self.conflictBase.epoch;
            } else {
                if (typeof self.latestServerVersion === 'number') {
                    self.version = self.latestServerVersion;
                }
                self.baseEpoch = self.epoch;
            }
            self.save();
        });

        var takeTheirs = promptButton('data-ui-collab-take-theirs', 'Take theirs', function () {
            self.discardLocalToServer();
        });

        body.actions.appendChild(keepMine);
        body.actions.appendChild(takeTheirs);
        this.setSaveStatus('error');
    };

    /** Take theirs: drop unsaved local edits and adopt the latest server snapshot. */
    CollabForm.prototype.discardLocalToServer = function () {
        this.dirtyDoc = false;
        if (typeof this.latestServerVersion === 'number') {
            this.version = this.latestServerVersion;
        }
        this.baseEpoch = this.epoch;
        // Theirs is the draft; with no draft (the record was saved and the
        // draft ended) it is what the save stored, when the app said.
        var draft = this.serverValues || {};
        var hasDraft = this.epoch !== '';
        var fields = Array.isArray(this.m.fields) ? this.m.fields : [];
        for (var i = 0; i < fields.length; i++) {
            var name = String(fields[i]);
            var input = this.inputOf(this.fieldEl(name));
            if (!input) {
                continue;
            }
            if (hasDraft) {
                var v = draft[name];
                input.value = (v === null || v === undefined) ? '' : String(v);
            } else if (Object.prototype.hasOwnProperty.call(this.savedValues, name)) {
                var stored = this.savedValues[name];
                input.value = (stored === null || stored === undefined) ? '' : String(stored);
            }
            // Neither: nothing here knows what the record holds, and the
            // input is left as it is rather than blanked or set to a guess.
        }
        this.clearConflict();
        this.setSaveStatus('saved');
    };

    CollabForm.prototype.startHeartbeat = function () {
        var self = this;
        this.emitPresencePing();
        this.heartbeatTimer = setInterval(function () {
            self.emitPresencePing();
            self.retryUnsentEdits(null, true);
        }, this.heartbeatMs);
    };

    CollabForm.prototype.destroy = function () {
        this.closed = true;
        // Release any held lock so a torn-down holder does not block the form (or a
        // field) until the TTL expires (the unsubscribe + release both ride the
        // still-open shared connection).
        this.releaseLock();
        this.releaseAllFieldLocks();
        if (this.channel) {
            // channel.close() hard-unsubscribes over the shared connection so
            // the server reaps this subscription's record (a surviving KISS
            // connection would otherwise keep it registered — the
            // orphaned-on-teardown failure mode) and/or closes the dedicated
            // stream + pending reconnect timer.
            this.channel.close();
            this.channel = null;
        }
        if (this.heartbeatTimer !== null) {
            clearInterval(this.heartbeatTimer);
            this.heartbeatTimer = null;
        }
        this.relockAfterSubmit();
        for (var i = 0; i < this.formListeners.length; i++) {
            var l = this.formListeners[i];
            l.form.removeEventListener(l.type, l.listener, l.capture);
        }
        this.formListeners = [];
        if (this.pageHideListener) {
            window.removeEventListener('pagehide', this.pageHideListener);
            this.pageHideListener = null;
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

    // The one element lifecycle (core.mount): a collab manifest now and later
    // boots its form; when it leaves the page the form unsubscribes so the
    // server reaps its subscription (the shared KISS stream survives).
    var collabForms = mount(MANIFEST_SELECTOR, {
        connect: function (scriptEl) {
            bootScript(scriptEl);
            var manifest = parseManifest(scriptEl);
            var key = manifest ? String(manifest.i || '') : '';
            return {
                destroy: function () {
                    if (key !== '' && booted[key]) {
                        try { booted[key].destroy(); } catch (e) { /* noop */ }
                        delete booted[key];
                    }
                }
            };
        }
    });

    function bootAll(root) {
        collabForms.scan(root);
    }

    window.SemitexaUi = window.SemitexaUi || {};
    window.SemitexaUi.formCollab = { bootAll: bootAll };
})();
