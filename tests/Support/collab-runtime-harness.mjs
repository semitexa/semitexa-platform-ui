// Runs the SHIPPED form-collab-runtime.js against a small recording DOM, for
// FormCollabRuntimeTest. stdin: {runtime, manifest, inputs, lockHost, save, steps, replies};
// replies maps an event to the queue of answers HUG gives it (default: accepted).
// A step is {snapshot}, {focus}, {type, value}, {save} or {click: '<attribute>'}.
// stdout: what a person would see (input values, presence chips, lock texts, prompts),
// what the page heard (events), what was posted, and every write to an HTML
// sink (innerHTML and the like) — a label must never reach one.
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
    dispatchEvent(ev) {
        ev.target = ev.target || this;
        this.heard.push({ type: ev.type, detail: ev.detail === undefined ? null : ev.detail });
        for (const fn of this.listeners[ev.type] || []) { fn(ev); }
        if (ev.bubbles && this.parent) { this.parent.dispatchEvent(ev); }
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

class FakeEvent { constructor(type, init) { this.type = type; this.bubbles = !!(init && init.bubbles); this.detail = init ? init.detail : undefined; } preventDefault() {} }

const documentEl = new El('html');
const fakeDocument = { activeElement: null, documentElement: documentEl, createElement: (tag) => new El(tag) };
const fakeWindow = {};

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
for (const [name, value] of Object.entries(scenario.inputs)) {
    const input = root.appendChild(new El('input'));
    input.setAttribute('data-ui-field-name', name);
    input.value = String(value);
    inputs[name] = input;
}
const manifestEl = root.appendChild(new El('script'));
manifestEl.textContent = JSON.stringify(scenario.manifest);

let mounted = null;
let feed = null;
const posts = [];
const replies = scenario.replies || {};
const core = {
    openFeedChannel: (opts) => { feed = opts; return { close() {} }; },
    mount: (_selector, def) => { mounted = def; return { scan() {} }; },
    envelope: (e) => e,
    hug: (e) => {
        posts.push({ event: e.semanticEvent, payload: e.payload });
        const queue = replies[e.semanticEvent] || [];
        return Promise.resolve({ data: queue.length ? queue.shift() : { status: 'accepted' } });
    },
};

const source = readFileSync(scenario.runtime, 'utf8').replace(/^import\s[^;]+;\s*$/m, '');
if (/^\s*import\s/m.test(source)) { throw new Error('the runtime has an import this harness does not provide'); }
new Function(
    'openFeedChannel', 'mount', 'envelope', 'hug', 'window', 'document', 'Event', 'CustomEvent', 'setInterval', 'clearInterval', 'setTimeout',
    source,
)(core.openFeedChannel, core.mount, core.envelope, core.hug, fakeWindow, fakeDocument, FakeEvent, FakeEvent, () => 1, () => {}, () => 1);

mounted.connect(manifestEl);
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
    htmlSinks,
}));
