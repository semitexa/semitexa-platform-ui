/**
 * Semitexa Platform UI — Behavior runtime (client-only interaction tier).
 *
 * The third UI tier. Where `event-runtime.js` owns SERVER round-trip events
 * (form submit, field change), this runtime owns CLIENT-ONLY interactions:
 * open a dropdown, toggle an accordion, trap focus in a modal. It never makes a
 * network request.
 *
 * Model (adapted from UIkit's auto-init, made native-ESM + token-first):
 *   - Server renders  <div ui-behavior="dropdown" ui-dropdown="mode: click; pos: bottom-start">.
 *   - ONE document MutationObserver auto-connects/disconnects behaviors by
 *     scanning `ui-behavior` (+ each registered `ui-<alias>` for live reconfig).
 *   - Each connected behavior instance is stashed ON the DOM node
 *     (`el.__sxBehaviors[alias]`) — the node IS the instance registry.
 *   - Options come from the `ui-<alias>="key: val; key: val"` mini-DSL, coerced
 *     to typed values using the behavior's declared option schema.
 *   - Teardown is centralised: every instance owns an AbortController; disconnect
 *     aborts it, so listeners/observers created by composables clean up for free.
 *
 * A behavior MODULE registers a definition:
 *   import { registerBehavior, useTogglable } from 'platform-ui/behaviors';
 *   registerBehavior({ name, ui, options, connect(el, opts, ctx) { … } });
 *
 * Public API (window.SemitexaUi.behaviors):
 *   .version                string
 *   .register(def)          register a behavior definition (also connects live matches)
 *   .connect(root?)         (re)scan a subtree and connect
 *   .instance(el, alias)    the live instance for an element+alias, or null
 *   .parseOptions(str, schema)  the DSL parser (exposed for tooling/tests)
 *
 * Idempotent: re-evaluating this module is a no-op.
 *
 * Composables (named ESM exports, used by behavior modules):
 *   useTransition, useTogglable, useFloating, useFocusTrap, useDismiss.
 */
import { onReady } from 'platform-ui/core';

const NS = 'platform-ui/behaviors';
const VERSION = '1.0';

// Double-eval guard — reuse the already-booted runtime if present.
const existing = (typeof window !== 'undefined' && window.SemitexaUi && window.SemitexaUi.behaviors) || null;

// -----------------------------------------------------------------------------
// Option DSL — parse `key: val; key: val` and coerce by the declared schema.
// Mirrors UIkit's parseOptions, driven by the server-authored option types.
// -----------------------------------------------------------------------------

const NODE_KEY = '__sxBehaviors';

/**
 * @param {string} raw   the `ui-<alias>` attribute value
 * @param {Array<{name:string,type:string,default:*,values?:string[]}>} schema
 */
function parseOptions(raw, schema) {
    schema = schema || [];
    const out = {};
    for (const opt of schema) {
        if (opt && opt.default !== undefined) out[opt.name] = opt.default;
    }
    const value = (raw == null ? '' : String(raw)).trim();
    if (value === '') return out;

    let pairs;
    if (value[0] === '{') {
        try { Object.assign(out, JSON.parse(value)); return coerceAll(out, schema); } catch (e) { /* fall through */ }
    }
    if (value.indexOf(':') === -1) {
        // single positional -> the first declared option
        if (schema[0]) out[schema[0].name] = value;
        return coerceAll(out, schema);
    }
    pairs = value.split(';');
    for (const piece of pairs) {
        const p = piece.trim();
        if (p === '') continue;
        const m = p.split(/:(.*)/); // split on first ':' only
        const key = (m[0] || '').trim();
        if (key === '') continue;
        out[key] = (m[1] === undefined ? '' : m[1]).trim();
    }
    return coerceAll(out, schema);
}

function coerceAll(map, schema) {
    for (const opt of (schema || [])) {
        if (opt && Object.prototype.hasOwnProperty.call(map, opt.name)) {
            map[opt.name] = coerce(map[opt.name], opt);
        }
    }
    return map;
}

function coerce(value, opt) {
    switch (opt.type) {
        case 'bool':
            if (value === '' || value === true) return true;
            if (value === false) return false;
            return String(value).toLowerCase() !== 'false' && value !== '0';
        case 'number': {
            const n = Number(value);
            return Number.isFinite(n) ? n : opt.default;
        }
        case 'list':
            if (Array.isArray(value)) return value;
            return String(value).split(/,(?![^(]*\))/).map((s) => s.trim()).filter(Boolean);
        case 'enum':
            return (opt.values && opt.values.indexOf(value) !== -1) ? value : opt.default;
        case 'selector':
        case 'string':
        default:
            return value;
    }
}

// -----------------------------------------------------------------------------
// Registry + instance lifecycle.
// -----------------------------------------------------------------------------

const byName = new Map();     // canonical name -> def
const byUi = new Map();       // ui alias -> canonical name
let booted = false;
let observer = null;          // the single document MutationObserver (rebuilt on late registration)

function registerBehavior(def) {
    if (!def || typeof def.connect !== 'function') {
        // eslint-disable-next-line no-console
        console.warn('[sx-behaviors] ignoring behavior without a connect() function', def);
        return;
    }
    const name = def.name || def.ui;
    const ui = def.ui || (name && name.split('.').pop());
    if (!ui) return;
    if (byUi.has(ui) && byUi.get(ui) !== name) {
        // eslint-disable-next-line no-console
        console.warn(`[sx-behaviors] alias "${ui}" already registered by "${byUi.get(ui)}"; ignoring ${name}`);
        return;
    }
    def = Object.assign({ name, ui, options: def.options || [] }, def);
    byName.set(name, def);
    byUi.set(ui, name);
    if (booted) {
        connect(document.body);   // live match for late registration
        installObserver();        // widen the observer's attributeFilter for the new alias
    }
    return def;
}

function aliasesOf(el) {
    const attr = el.getAttribute && el.getAttribute('ui-behavior');
    if (!attr) return [];
    return attr.split(/\s+/).map((s) => s.trim()).filter(Boolean);
}

function connectEl(el) {
    const store = el[NODE_KEY] || (el[NODE_KEY] = {});
    for (const alias of aliasesOf(el)) {
        if (store[alias]) continue;             // already connected
        const def = byUi.get(alias) && byName.get(byUi.get(alias));
        if (!def) continue;                     // behavior not registered (yet)
        const controller = new AbortController();
        const opts = parseOptions(el.getAttribute('ui-' + alias), def.options);
        const ctx = makeCtx(el, controller, def);
        const inst = { def, opts, controller, ctx, api: undefined };
        store[alias] = inst;
        try {
            inst.api = def.connect(el, opts, ctx) || {};
        } catch (e) {
            // eslint-disable-next-line no-console
            console.error(`[sx-behaviors] connect failed for "${alias}"`, e);
            delete store[alias];
            controller.abort();
        }
    }
}

function disconnectEl(el, alias) {
    const store = el[NODE_KEY];
    if (!store) return;
    const kill = (a) => {
        const inst = store[a];
        if (!inst) return;
        try { if (inst.api && typeof inst.api.destroy === 'function') inst.api.destroy(); } catch (e) { /* ignore */ }
        try { inst.controller.abort(); } catch (e) { /* ignore */ }
        delete store[a];
    };
    if (alias) kill(alias); else Object.keys(store).forEach(kill);
}

function reconfigureEl(el, alias) {
    disconnectEl(el, alias);
    connectEl(el);
}

/** The context handed to a behavior's connect(). */
function makeCtx(el, controller, def) {
    return {
        root: el,
        signal: controller.signal,
        reduceMotion: prefersReducedMotion(),
        q: (sel) => el.querySelector(sel),
        qa: (sel) => Array.prototype.slice.call(el.querySelectorAll(sel)),
        /** Find a role-hook element within the behavior root. */
        role: (name) => el.querySelector('[ui-behavior-' + name + ']'),
        roles: (name) => Array.prototype.slice.call(el.querySelectorAll('[ui-behavior-' + name + ']')),
        on: (target, type, handler, opts) => {
            target.addEventListener(type, handler, Object.assign({ signal: controller.signal }, opts || {}));
        },
        emit: (type, detail) => {
            el.dispatchEvent(new CustomEvent('sx:' + def.ui + ':' + type, { bubbles: true, detail: detail || {} }));
        },
    };
}

function connect(root) {
    root = root || document.body;
    if (!root) return;
    if (root.nodeType === 1 && root.hasAttribute && root.hasAttribute('ui-behavior')) connectEl(root);
    const nodes = root.querySelectorAll ? root.querySelectorAll('[ui-behavior]') : [];
    for (let i = 0; i < nodes.length; i++) connectEl(nodes[i]);
}

function walk(node, fn) {
    if (node.nodeType !== 1) return;
    if (node.hasAttribute && node.hasAttribute('ui-behavior')) fn(node);
    if (!node.querySelectorAll) return;
    const nodes = node.querySelectorAll('[ui-behavior]');
    for (let i = 0; i < nodes.length; i++) fn(nodes[i]);
}

function installObserver() {
    if (typeof MutationObserver === 'undefined' || !document.body) return;
    const attrFilter = ['ui-behavior'];
    byUi.forEach((_n, ui) => attrFilter.push('ui-' + ui));
    // Rebuild from scratch so a behavior registered AFTER boot widens the
    // attributeFilter to include its new `ui-<alias>` (else its option changes
    // would never reach reconfigureEl).
    if (observer) observer.disconnect();
    observer = new MutationObserver((mutations) => {
        for (const m of mutations) {
            if (m.type === 'childList') {
                m.addedNodes.forEach((n) => walk(n, connectEl));
                m.removedNodes.forEach((n) => walk(n, (el) => disconnectEl(el)));
            } else if (m.type === 'attributes') {
                const el = m.target;
                if (m.attributeName === 'ui-behavior') {
                    // aliases changed: drop instances whose alias is gone, connect new ones
                    const live = aliasesOf(el);
                    const store = el[NODE_KEY] || {};
                    Object.keys(store).forEach((a) => { if (live.indexOf(a) === -1) disconnectEl(el, a); });
                    connectEl(el);
                } else if (m.attributeName && m.attributeName.indexOf('ui-') === 0) {
                    reconfigureEl(el, m.attributeName.slice(3));
                }
            }
        }
    });
    observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: attrFilter });
    return observer;
}

function boot() {
    if (booted) return;
    booted = true;
    connect(document.body);
    installObserver();
}

// -----------------------------------------------------------------------------
// Composables — the shared quality engines. Every behavior composes these so
// motion / focus / a11y are uniform and centrally fixable.
// -----------------------------------------------------------------------------

function prefersReducedMotion() {
    return typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/**
 * Promise/interruptible transition: toggle `openClass`, resolve when the CSS
 * transition/animation ends (or immediately under reduced-motion / no motion).
 */
function useTransition(el, { openClass = 'sx-open', timeout = 400 } = {}) {
    let pending = null;
    function run(show) {
        if (pending) { pending.resolve(); pending = null; }
        return new Promise((resolve) => {
            const finish = () => { cleanup(); resolve(); };
            const cleanup = () => {
                el.removeEventListener('transitionend', onEnd);
                el.removeEventListener('animationend', onEnd);
                clearTimeout(timer);
                pending = null;
            };
            const onEnd = (e) => { if (e.target === el) finish(); };
            pending = { resolve: finish };
            // Force a reflow so the browser commits the pre-toggle (closed) state
            // and actually transitions. Without it a synchronous reveal+toggle
            // lands in one frame, no transition fires, and show() would stall on
            // the fallback timeout — delaying everything the caller does after.
            void el.offsetWidth;
            el.classList.toggle(openClass, show);
            if (prefersReducedMotion()) { finish(); return; }
            const cs = getComputedStyle(el);
            const hasMotion = (parseFloat(cs.transitionDuration) || 0) > 0 || (parseFloat(cs.animationDuration) || 0) > 0;
            if (!hasMotion) { finish(); return; }
            el.addEventListener('transitionend', onEnd);
            el.addEventListener('animationend', onEnd);
            var timer = setTimeout(finish, timeout);
        });
    }
    return { show: () => run(true), hide: () => run(false) };
}

/**
 * Unified show/hide engine (UIkit's Togglable). Syncs aria-expanded on the
 * trigger and hidden/open-class on the content; Promise-returning.
 */
function useTogglable(content, { trigger = null, openClass = 'sx-open', onShow, onHide } = {}) {
    const transition = useTransition(content, { openClass });
    let open = !content.hasAttribute('hidden') && content.classList.contains(openClass);
    function setExpanded(v) { if (trigger) trigger.setAttribute('aria-expanded', v ? 'true' : 'false'); }
    setExpanded(open);
    async function show() {
        if (open) return;
        open = true;
        content.hidden = false;
        setExpanded(true);
        if (onShow) onShow();
        await transition.show();
    }
    async function hide() {
        if (!open) return;
        open = false;
        setExpanded(false);
        await transition.hide();
        content.hidden = true;
        if (onHide) onHide();
    }
    return {
        isOpen: () => open,
        show,
        hide,
        toggle: () => (open ? hide() : show()),
    };
}

/**
 * Position `floater` against `anchor`. CSS Anchor Positioning when supported;
 * a compact JS fallback (getBoundingClientRect + viewport-overflow flip) with
 * the same option surface otherwise. `pos` uses logical block/inline sides
 * (e.g. "bottom-start", "top-end").
 */
function useFloating(anchor, floater, { pos = 'bottom-start', offset = 4, flip = true, boundary = null } = {}) {
    const nativeAnchor = typeof CSS !== 'undefined' && CSS.supports && CSS.supports('anchor-name: --x');
    let cleanupFns = [];
    function applyNative() {
        const id = anchor.style.anchorName || ('--sx-anchor-' + Math.random().toString(36).slice(2, 8));
        anchor.style.anchorName = id;
        floater.style.positionAnchor = id;
        floater.style.position = 'fixed';
        floater.style.positionArea = logicalToArea(pos);
        floater.style.marginBlock = offset + 'px';
        if (flip) floater.style.positionTryFallbacks = 'flip-block, flip-inline';
    }
    function applyFallback() {
        const place = () => positionFallback(anchor, floater, pos, offset, flip, boundary);
        place();
        const opts = { passive: true };
        window.addEventListener('scroll', place, opts);
        window.addEventListener('resize', place, opts);
        cleanupFns.push(() => { window.removeEventListener('scroll', place, opts); window.removeEventListener('resize', place, opts); });
    }
    function update() { if (nativeAnchor) applyNative(); else applyFallback(); }
    update();
    return {
        update,
        destroy() { cleanupFns.forEach((f) => f()); cleanupFns = []; },
    };
}

function logicalToArea(pos) {
    // "bottom-start" -> "block-end span-inline-end", etc. Minimal mapping.
    const [side, align] = pos.split('-');
    const block = side === 'top' ? 'block-start' : side === 'bottom' ? 'block-end' : 'block-end';
    const inline = align === 'end' ? 'span-inline-start' : 'span-inline-end';
    if (side === 'left') return 'inline-start ' + (align === 'end' ? 'span-block-start' : 'span-block-end');
    if (side === 'right') return 'inline-end ' + (align === 'end' ? 'span-block-start' : 'span-block-end');
    return block + ' ' + inline;
}

function positionFallback(anchor, floater, pos, offset, flip, boundary) {
    const a = anchor.getBoundingClientRect();
    floater.style.position = 'fixed';
    const fw = floater.offsetWidth;
    const fh = floater.offsetHeight;
    const vw = document.documentElement.clientWidth;
    const vh = document.documentElement.clientHeight;
    let [side, align] = pos.split('-');
    // vertical flip if it would clip
    if (flip) {
        if (side === 'bottom' && a.bottom + offset + fh > vh && a.top - offset - fh > 0) side = 'top';
        else if (side === 'top' && a.top - offset - fh < 0 && a.bottom + offset + fh < vh) side = 'bottom';
    }
    let top; let left;
    if (side === 'top') top = a.top - offset - fh;
    else if (side === 'bottom') top = a.bottom + offset;
    else top = a.top;
    if (side === 'left') left = a.left - offset - fw;
    else if (side === 'right') left = a.right + offset;
    else left = (align === 'end') ? a.right - fw : a.left;
    // clamp into viewport
    left = Math.max(4, Math.min(left, vw - fw - 4));
    top = Math.max(4, Math.min(top, vh - fh - 4));
    floater.style.top = top + 'px';
    floater.style.left = left + 'px';
}

/**
 * Real focus trap: mark all siblings outside `container` as inert, wrap Tab, and
 * restore focus on release. Fixes UIkit's missing trap.
 */
function useFocusTrap(container, { returnTo = null } = {}) {
    const explicitReturnTo = returnTo;
    let controller = null;
    let inerted = [];
    let restoreTarget = null;
    const focusables = () => Array.prototype.slice.call(
        container.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])'),
    ).filter((el) => el.offsetParent !== null || el === document.activeElement);
    function activate() {
        controller = new AbortController();
        // Recompute each cycle: an explicit returnTo always wins, otherwise
        // capture whatever is focused NOW (not a stale value from a prior open).
        restoreTarget = explicitReturnTo || document.activeElement;
        // inert everything outside the container
        let node = container;
        while (node && node !== document.body && node.parentElement) {
            Array.prototype.forEach.call(node.parentElement.children, (sib) => {
                if (sib !== node && !sib.hasAttribute('inert')) { sib.setAttribute('inert', ''); inerted.push(sib); }
            });
            node = node.parentElement;
        }
        const first = focusables()[0] || container;
        first.focus({ preventScroll: true });
        container.addEventListener('keydown', (e) => {
            if (e.key !== 'Tab') return;
            const f = focusables();
            if (f.length === 0) { e.preventDefault(); return; }
            const firstEl = f[0]; const lastEl = f[f.length - 1];
            if (e.shiftKey && document.activeElement === firstEl) { e.preventDefault(); lastEl.focus(); }
            else if (!e.shiftKey && document.activeElement === lastEl) { e.preventDefault(); firstEl.focus(); }
        }, { signal: controller.signal });
    }
    function release() {
        if (controller) controller.abort();
        inerted.forEach((el) => el.removeAttribute('inert'));
        inerted = [];
        if (restoreTarget && typeof restoreTarget.focus === 'function') restoreTarget.focus({ preventScroll: true });
        restoreTarget = null;
    }
    return { activate, release };
}

/**
 * Dismiss on Escape / outside pointer / focus-leave. One AbortController owns
 * every listener.
 */
function useDismiss(root, { onDismiss, esc = true, outside = true } = {}) {
    let controller = null;
    function activate() {
        controller = new AbortController();
        const signal = controller.signal;
        if (esc) {
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') onDismiss('esc'); }, { signal });
        }
        if (outside) {
            document.addEventListener('pointerdown', (e) => { if (!root.contains(e.target)) onDismiss('outside'); }, { signal, capture: true });
            document.addEventListener('focusin', (e) => { if (!root.contains(e.target)) onDismiss('focus'); }, { signal });
        }
    }
    function release() { if (controller) controller.abort(); controller = null; }
    return { activate, release };
}

/**
 * Observe when an element enters/leaves the viewport (IntersectionObserver).
 * Auto-disconnects when the given AbortSignal aborts. Powers scrollspy reveals
 * and the sticky "stuck" sentinel. Degrades to an immediate onEnter when IO is
 * unavailable.
 */
function useInView(el, { onEnter, onLeave, once = false, threshold = 0, rootMargin = '0px', signal } = {}) {
    if (typeof IntersectionObserver === 'undefined') {
        if (onEnter) onEnter(null);
        return { destroy() {} };
    }
    const io = new IntersectionObserver((entries) => {
        for (const e of entries) {
            if (e.isIntersecting) { if (onEnter) onEnter(e); if (once) io.disconnect(); }
            else if (onLeave) onLeave(e);
        }
    }, { threshold, rootMargin });
    io.observe(el);
    if (signal) signal.addEventListener('abort', () => io.disconnect(), { once: true });
    return { destroy() { io.disconnect(); } };
}

// Ref-counted body scroll lock — shared by modal/offcanvas so nested overlays
// don't unlock the page prematurely.
let scrollLockCount = 0;
function useScrollLock() {
    return {
        lock() { if (scrollLockCount++ === 0) document.documentElement.style.overflow = 'hidden'; },
        unlock() { if (scrollLockCount > 0 && --scrollLockCount === 0) document.documentElement.style.overflow = ''; },
    };
}

// -----------------------------------------------------------------------------
// Public API + boot.
// -----------------------------------------------------------------------------

const api = existing || {
    version: VERSION,
    register: registerBehavior,
    connect,
    parseOptions,
    instance: (el, alias) => (el && el[NODE_KEY] && el[NODE_KEY][alias]) || null,
};

if (typeof window !== 'undefined') {
    window.SemitexaUi = window.SemitexaUi || {};
    window.SemitexaUi.behaviors = api;
}

if (!existing) {
    onReady(boot);
}

export {
    registerBehavior,
    parseOptions,
    connect,
    useTransition,
    useTogglable,
    useFloating,
    useFocusTrap,
    useDismiss,
    useInView,
    useScrollLock,
};
export default api;
