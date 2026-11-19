// Runs the SHIPPED form-collab-runtime.js against a small recording DOM, for
// FormCollabRuntimeTest. stdin: {runtime, manifest, inputs, lockHost, save, steps, replies,
// hold, form, controls};
// replies maps an event to the queue of answers HUG gives it (default: accepted);
// an answer {http: 503} is that status with no body, {http: 422, data} one with a body,
// {http: 422, stall: true} one whose status arrives and whose body never does.
// hold lists the events whose requests stay in flight until a step answers them.
// form puts the inputs in a <form>; controls gives an input another shape:
// {tag, type, name, checked, multiple, options: [{value, selected}]} (name null = no name),
// disabled (the page rendered it disabled), fieldset (inside a disabled <fieldset>),
// form: '<key>' (in a second <form> of that key, not the first).
// A step is {snapshot}, {focus}, {blur}, {type, value} (the value is set and `input` fired:
// after a {focus} a person typing, with none a page script filling the field), {save},
// {click: '<attribute>'},
// {answer: 'oldest' | 'newest-first'} (the server gets and answers one held request, or
// all of them and what they cause, newest first), {fail: 'oldest'} (it never arrives),
// {tick} (every interval fires once), {elapse: ms} (the clock moves; timeouts due fire),
// {pagehide}, {unmount} (the form leaves the page), {enable} (the page enables an input
// itself), {formData} (what `new FormData(form)` holds, built outside any submit) or
// {submit: true | {prevent, appFormData, form}} (the form, or the second form of that key,
// is submitted: the app's own submit
// listener may build a FormData and may cancel; unless cancelled the browser then builds
// the data it posts).
// stdout: what a person would see (input values, presence chips, lock texts, prompts),
// what the page heard (events), what was posted, what the server received and in
// which order (served), which posts asked for keepalive, how many requests the page
// aborted, the most requests one field had in flight at once, the form data (the
// browser's, the app's own, and which inputs were disabled right after the submit),
// and every write to an HTML sink (innerHTML and the like) — a label must never
// reach one.
import { readFileSync } from 'node:fs';

const scenario = JSON.parse(readFileSync(0, 'utf8'));
const htmlSinks = [];

class El {
    constructor(tag) {
        this.tagName = tag.toUpperCase();
        this.attrs = {};
        this.children = [];
        this.parent = null;
        this.listeners = {};
        this.heard = [];
        this.text = '';
        this.value = '';
        this.disabled = false;
        this.style = {};
        this.className = '';
    }
    get textContent() { return this.text + this.children.map((c) => c.textContent).join(''); }
    set textContent(v) { this.children = []; this.text = String(v); }
    set innerHTML(v) { htmlSinks.push(['innerHTML', String(v)]); }
    set outerHTML(v) { htmlSinks.push(['outerHTML', String(v)]); }
    insertAdjacentHTML(_where, v) { htmlSinks.push(['insertAdjacentHTML', String(v)]); }
    setAttribute(n, v) { this.attrs[n] = String(v); }
    getAttribute(n) { return Object.prototype.hasOwnProperty.call(this.attrs, n) ? this.attrs[n] : null; }
    removeAttribute(n) { delete this.attrs[n]; }
    appendChild(c) { c.parent = this; this.children.push(c); return c; }
    focus() { fakeDocument.activeElement = this; }
    contains(o) { for (let n = o; n; n = n.parent) { if (n === this) { return true; } } return false; }
    addEventListener(t, fn) { (this.listeners[t] = this.listeners[t] || []).push(fn); }
    removeEventListener(t, fn) { this.listeners[t] = (this.listeners[t] || []).filter((f) => f !== fn); }
    get elements() {
        const out = [];
        const walk = (el) => { if (['INPUT', 'SELECT', 'TEXTAREA', 'BUTTON', 'FIELDSET'].includes(el.tagName)) { out.push(el); } el.children.forEach(walk); };
        this.children.forEach(walk);
        return out;
    }
    get form() { for (let n = this.parent; n; n = n.parent) { if (n.tagName === 'FORM') { return n; } } return null; }
    dispatchEvent(ev) {
        const outermost = !ev.eventPhase;
        if (outermost) { ev.eventPhase = 2; }
        ev.target = ev.target || this;
        this.heard.push({ type: ev.type, detail: ev.detail === undefined ? null : ev.detail });
        for (const fn of this.listeners[ev.type] || []) { fn(ev); }
        if (ev.bubbles && this.parent) { this.parent.dispatchEvent(ev); }
        if (outermost) { ev.eventPhase = 0; }
        return true;
    }
    matches(selector) { return selector.split(',').some((alt) => matchesOne(this, alt.trim())); }
    closest(selector) { for (let n = this; n; n = n.parent) { if (n.matches(selector)) { return n; } } return null; }
    querySelector(selector) {
        for (const c of this.children) {
            if (c.matches(selector)) { return c; }
            const deep = c.querySelector(selector);
            if (deep) { return deep; }
        }
        return null;
    }
}

// tag and [attr] / [attr="value"] conditions: all the runtime asks for.
function matchesOne(el, selector) {
    const tag = (selector.match(/^[a-z]+/i) || [''])[0];
    if (tag !== '' && el.tagName !== tag.toUpperCase()) { return false; }
    for (const m of selector.matchAll(/\[([^\]=]+)(?:="((?:[^"\\]|\\.)*)")?\]/g)) {
        const actual = el.getAttribute(m[1]);
        if (actual === null) { return false; }
        if (m[2] !== undefined && actual !== m[2].replace(/\\(.)/g, '$1')) { return false; }
    }
    return true;
}

class FakeEvent { constructor(type, init) { this.type = type; this.bubbles = !!(init && init.bubbles); this.detail = init ? init.detail : undefined; this.defaultPrevented = false; this.eventPhase = 0; } preventDefault() { this.defaultPrevented = true; } }

const documentEl = new El('html');
const fakeDocument = { activeElement: null, documentElement: documentEl, createElement: (tag) => new El(tag) };
const windowListeners = {};
const fakeWindow = {
    addEventListener: (t, fn) => { (windowListeners[t] = windowListeners[t] || []).push(fn); },
    removeEventListener: (t, fn) => { windowListeners[t] = (windowListeners[t] || []).filter((f) => f !== fn); },
};

const root = documentEl.appendChild(new El('div'));
root.setAttribute('data-ui-component-instance-id', String(scenario.manifest.i));
const host = (attr) => { const el = root.appendChild(new El('span')); el.setAttribute(attr, ''); return el; };
const presenceHost = host('data-ui-collab-presence');
host('data-ui-collab-status');
const lockHost = scenario.lockHost ? host('data-ui-collab-lock') : null;
const saveStatus = host('data-ui-collab-save-status');
const conflictHost = host('data-ui-collab-conflict');
conflictHost.setAttribute('hidden', '');
const saveButton = scenario.save ? host('data-ui-collab-save') : null;
const inputs = {};
const formEl = scenario.form ? root.appendChild(new El('form')) : null;
const otherForms = {};
for (const [name, value] of Object.entries(scenario.inputs)) {
    const shape = (scenario.controls || {})[name] || {};
    let holder = formEl || root;
    if (shape.form) { holder = otherForms[shape.form] || (otherForms[shape.form] = root.appendChild(new El('form'))); }
    if (shape.fieldset) { holder = holder.appendChild(new El('fieldset')); holder.disabled = true; }
    const input = holder.appendChild(new El(shape.tag || 'input'));
    input.disabled = !!shape.disabled;
    input.setAttribute('data-ui-field-name', name);
    input.value = String(value);
    input.type = shape.type || (input.tagName === 'INPUT' ? 'text' : input.tagName.toLowerCase());
    input.name = shape.name === null ? '' : (shape.name || name);
    input.checked = !!shape.checked;
    input.multiple = !!shape.multiple;
    input.options = (shape.options || []).map((o) => ({ value: String(o.value), selected: !!o.selected, disabled: false }));
    inputs[name] = input;
}

// What a browser puts in a form's data: named, enabled controls only.
class FakeFormData {
    constructor(form) {
        this.entries = [];
        const walk = (el) => {
            if (el.tagName === 'FIELDSET' && el.disabled) { return; }
            if (['INPUT', 'SELECT', 'TEXTAREA'].includes(el.tagName) && el.name !== '' && !el.disabled
                && !['submit', 'reset', 'button', 'image'].includes(el.type)) {
                if (el.tagName === 'SELECT') {
                    const chosen = el.options.filter((o) => o.selected);
                    for (const o of el.multiple ? chosen : chosen.slice(0, 1)) { this.append(el.name, o.value); }
                } else if ((el.type !== 'checkbox' && el.type !== 'radio') || el.checked) {
                    this.append(el.name, el.value);
                }
            }
            el.children.forEach(walk);
        };
        walk(form);
    }
    append(name, value) { this.entries.push([String(name), value]); }
    delete(name) { this.entries = this.entries.filter((e) => e[0] !== name); }
    getAll(name) { return this.entries.filter((e) => e[0] === name).map((e) => e[1]); }
    has(name) { return this.entries.some((e) => e[0] === name); }
}
const manifestEl = root.appendChild(new El('script'));
manifestEl.textContent = JSON.stringify(scenario.manifest);

let mounted = null;
let feed = null;
const posts = [];
const served = [];
const held = [];
let intervals = [];
let intervalId = 0;
let appFormData = null;
let disabledAfterSubmit = null;
const disabledNow = () => Object.fromEntries(Object.entries(inputs).map(([n, el]) => [n, el.disabled]));
const keepalive = [];
let aborted = 0;
let clock = 0;
let timeoutId = 0;
let timeouts = [];
const fakeSetTimeout = (fn, ms) => { timeouts.push({ id: ++timeoutId, fn, at: clock + (ms || 0) }); return timeoutId; };
const fakeClearTimeout = (id) => { timeouts = timeouts.filter((t) => t.id !== id); };
const flying = {};
const maxInFlight = {};
let formData = null;
const replies = scenario.replies || {};
const holds = scenario.hold || [];
const fieldOf = (e) => (e.semanticEvent === 'field.edit' ? String(e.payload.field) : null);
// The answer of the real client: the status first (opts.onResponse), then the body.
const serve = (e, opts) => {
    served.push({ event: e.semanticEvent, payload: e.payload });
    const queue = replies[e.semanticEvent] || [];
    const reply = queue.length ? queue.shift() : { status: 'accepted' };
    const http = reply && typeof reply.http === 'number' ? reply.http : null;
    if (opts && typeof opts.onResponse === 'function') { opts.onResponse({ ok: http === null || http < 400, status: http === null ? 200 : http }); }
    if (http === null) { return { data: reply }; }
    if (reply.stall) { return new Promise(() => {}); }
    return { ok: http < 400, status: http, data: reply.data === undefined ? null : reply.data };
};
const landed = (e) => { const f = fieldOf(e); if (f !== null) { flying[f]--; } };
const core = {
    openFeedChannel: (opts) => { feed = opts; return { close() {} }; },
    mount: (_selector, def) => { mounted = def; return { scan() {} }; },
    envelope: (e) => e,
    hug: (e, opts) => {
        posts.push({ event: e.semanticEvent, payload: e.payload });
        if (opts && opts.keepalive === true) { keepalive.push({ event: e.semanticEvent, payload: e.payload }); }
        const f = fieldOf(e);
        if (f !== null) {
            flying[f] = (flying[f] || 0) + 1;
            maxInFlight[f] = Math.max(maxInFlight[f] || 0, flying[f]);
        }
        if (holds.includes(e.semanticEvent)) {
            return new Promise((resolve, reject) => {
                const request = {
                    answer: () => { landed(e); resolve(serve(e, opts)); },
                    fail: () => { landed(e); reject(new TypeError('Failed to fetch')); },
                };
                held.push(request);
                if (opts && opts.signal) {
                    opts.signal.addEventListener('abort', () => {
                        const at = held.indexOf(request);
                        if (at === -1) { return; }
                        held.splice(at, 1);
                        aborted++;
                        landed(e);
                        reject(new Error('aborted'));
                    });
                }
            });
        }
        return Promise.resolve().then(() => { landed(e); return serve(e, opts); });
    },
};

const source = readFileSync(scenario.runtime, 'utf8').replace(/^import\s[^;]+;\s*$/m, '');
if (/^\s*import\s/m.test(source)) { throw new Error('the runtime has an import this harness does not provide'); }
new Function(
    'openFeedChannel', 'mount', 'envelope', 'hug', 'window', 'document', 'Event', 'CustomEvent', 'setInterval', 'clearInterval', 'setTimeout', 'clearTimeout',
    source,
)(core.openFeedChannel, core.mount, core.envelope, core.hug, fakeWindow, fakeDocument, FakeEvent, FakeEvent, (fn) => { intervals.push({ id: ++intervalId, fn }); return intervalId; }, (id) => { intervals = intervals.filter((t) => t.id !== id); }, fakeSetTimeout, fakeClearTimeout);

const mountedForm = mounted.connect(manifestEl);
const settle = () => new Promise((resolve) => setImmediate(resolve));

for (const step of scenario.steps) {
    if (step.snapshot) {
        feed.onData(step.snapshot);
    } else if (step.focus) {
        fakeDocument.activeElement = inputs[step.focus];
        inputs[step.focus].dispatchEvent(new FakeEvent('focus'));
    } else if (step.type) {
        inputs[step.type].value = String(step.value);
        inputs[step.type].dispatchEvent(new FakeEvent('input', { bubbles: true }));
    } else if (step.blur) {
        if (fakeDocument.activeElement === inputs[step.blur]) { fakeDocument.activeElement = null; }
        inputs[step.blur].dispatchEvent(new FakeEvent('blur'));
    } else if (step.answer === 'oldest') {
        held.shift().answer();
    } else if (step.answer === 'newest-first') {
        while (held.length) { held.pop().answer(); await settle(); }
    } else if (step.fail === 'oldest') {
        held.shift().fail();
    } else if (step.unmount) {
        mountedForm.destroy();
    } else if (step.elapse) {
        const until = clock + step.elapse;
        for (let due = timeouts.filter((t) => t.at <= until); due.length; due = timeouts.filter((t) => t.at <= until)) {
            const next = due.sort((a, b) => a.at - b.at)[0];
            clock = next.at;
            fakeClearTimeout(next.id);
            next.fn();
            await settle();
        }
        clock = until;
    } else if (step.tick) {
        intervals.slice().forEach((t) => t.fn());
    } else if (step.pagehide) {
        (windowListeners.pagehide || []).forEach((fn) => fn(new FakeEvent('pagehide')));
    } else if (step.enable) {
        inputs[step.enable].disabled = false;
    } else if (step.submit) {
        const how = step.submit === true ? {} : step.submit;
        const target = how.form ? otherForms[how.form] : formEl;
        const ev = new FakeEvent('submit', { bubbles: true });
        // The app's own listener, added after the runtime's.
        const app = () => {
            if (how.appFormData) {
                const fd = new FakeEvent('formdata');
                fd.formData = new FakeFormData(target);
                target.dispatchEvent(fd);
                appFormData = fd.formData.entries;
            }
            if (how.prevent) { ev.preventDefault(); }
        };
        target.addEventListener('submit', app);
        target.dispatchEvent(ev);
        target.removeEventListener('submit', app);
        if (!ev.defaultPrevented) {
            const fd = new FakeEvent('formdata');
            fd.formData = new FakeFormData(target);
            target.dispatchEvent(fd);
            formData = fd.formData.entries;
        }
        disabledAfterSubmit = disabledNow();
    } else if (step.formData) {
        const ev = new FakeEvent('formdata');
        ev.formData = new FakeFormData(formEl);
        formEl.dispatchEvent(ev);
        formData = ev.formData.entries;
    } else if (step.save) {
        saveButton.dispatchEvent(new FakeEvent('click'));
    } else if (step.click) {
        const target = root.querySelector('[' + step.click + ']');
        if (!target) { throw new Error('nothing to click: ' + step.click); }
        target.dispatchEvent(new FakeEvent('click'));
    }
    await settle();
}

const buttonsIn = (el) => el.children.flatMap((c) => (c.tagName === 'BUTTON' ? [c.textContent] : buttonsIn(c)));
const describe = (el) => ({ text: el.textContent, attrs: el.attrs, elements: el.children.length, hidden: el.getAttribute('hidden') !== null });
process.stdout.write(JSON.stringify({
    values: Object.fromEntries(Object.entries(inputs).map(([n, el]) => [n, el.value])),
    fieldLocks: Object.fromEntries(Object.entries(inputs).map(([n, el]) => [n, el.getAttribute('data-ui-collab-field-lock')])),
    chips: presenceHost.children.map(describe),
    lock: lockHost ? describe(lockHost) : null,
    saveStatus: saveStatus.textContent,
    conflict: { ...describe(conflictHost), buttons: buttonsIn(conflictHost) },
    events: root.heard.filter((e) => e.type.startsWith('semitexa:')),
    posts,
    served,
    keepalive,
    aborted,
    maxInFlight,
    disabled: disabledNow(),
    disabledAfterSubmit,
    formData,
    appFormData,
    htmlSinks,
}));
