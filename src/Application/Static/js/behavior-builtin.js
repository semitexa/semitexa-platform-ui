/**
 * Semitexa Platform UI — built-in behaviors: `toggle` + `dropdown`.
 *
 * The flagship proof of the behavior tier. Each registers a definition with the
 * runtime and composes the shared composables — zero bespoke focus/positioning
 * code. Loaded globally (like event-runtime); the behavior only activates on
 * elements the server marked with `ui-behavior="toggle|dropdown"`.
 *
 * Import graph guarantees the runtime is initialized before this registers.
 * Idempotent: re-registration of the same alias is a no-op in the runtime.
 */
import {
    registerBehavior,
    useTogglable,
    useFloating,
    useFocusTrap,
    useDismiss,
    useInView,
    useScrollLock,
} from 'platform-ui/behaviors';

// -----------------------------------------------------------------------------
// toggle — generic show/hide from a trigger.
// -----------------------------------------------------------------------------
registerBehavior({
    name: 'platform.toggle',
    ui: 'toggle',
    options: [
        { name: 'target', type: 'selector' },
        { name: 'mode', type: 'enum', default: 'click', values: ['click', 'hover'] },
        { name: 'openClass', type: 'string', default: 'sx-open' },
    ],
    connect(el, opts, ctx) {
        const target = (opts.target && document.querySelector(opts.target)) || ctx.role('content');
        if (!target) return {};
        const t = useTogglable(target, { trigger: el, openClass: opts.openClass });
        if (opts.mode === 'hover') {
            ctx.on(el, 'mouseenter', () => t.show());
            ctx.on(el, 'mouseleave', () => t.hide());
            ctx.on(el, 'focusin', () => t.show());
        } else {
            ctx.on(el, 'click', (e) => { e.preventDefault(); t.toggle(); });
        }
        return {};
    },
});

// -----------------------------------------------------------------------------
// dropdown — floating panel with focus trap + dismissal + arrow-nav.
// -----------------------------------------------------------------------------
registerBehavior({
    name: 'platform.dropdown',
    ui: 'dropdown',
    options: [
        { name: 'mode', type: 'enum', default: 'click', values: ['click', 'hover'] },
        { name: 'pos', type: 'enum', default: 'bottom-start', values: ['bottom-start', 'bottom-end', 'top-start', 'top-end', 'left', 'right'] },
        { name: 'offset', type: 'number', default: 4 },
        { name: 'flip', type: 'bool', default: true },
    ],
    connect(el, opts, ctx) {
        const trigger = ctx.role('toggle') || ctx.q('[ui="button"]') || el;
        const content = ctx.role('content');
        if (!content) return {};

        const togglable = useTogglable(content, { trigger, openClass: 'sx-open' });
        let floating = null;
        // Always return focus to the trigger on close (robust regardless of what
        // was focused when the panel opened — e.g. a programmatic open).
        const focus = useFocusTrap(content, { returnTo: trigger });
        const dismiss = useDismiss(el, { onDismiss: () => close(), esc: true, outside: true });

        function open() {
            if (togglable.isOpen()) return;
            // Reveal, position, and wire interaction SYNCHRONOUSLY — dismissal,
            // focus trap and positioning must be live the instant the panel
            // appears, never gated behind the reveal transition.
            content.hidden = false;
            floating = useFloating(trigger, content, { pos: opts.pos, offset: opts.offset, flip: opts.flip });
            dismiss.activate();
            focus.activate();
            ctx.emit('open', {});
            togglable.show(); // aria-expanded + open flag + reveal transition (fire-and-forget)
        }
        function close() {
            if (!togglable.isOpen()) return;
            dismiss.release();
            focus.release();
            if (floating) { floating.destroy(); floating = null; }
            ctx.emit('close', {});
            togglable.hide(); // reverse transition, then hidden (fire-and-forget)
        }

        if (opts.mode === 'hover') {
            ctx.on(el, 'mouseenter', open);
            ctx.on(el, 'mouseleave', close);
        } else {
            ctx.on(trigger, 'click', (e) => { e.preventDefault(); togglable.isOpen() ? close() : open(); });
        }

        // arrow-nav: move focus among menu items inside the open panel.
        ctx.on(content, 'keydown', (e) => {
            if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
            const items = ctx.roles('item').filter((i) => i.offsetParent !== null);
            if (items.length === 0) return;
            e.preventDefault();
            const i = items.indexOf(document.activeElement);
            const next = e.key === 'ArrowDown' ? (i + 1) % items.length : (i - 1 + items.length) % items.length;
            items[next].focus();
        });

        return { destroy() { dismiss.release(); focus.release(); if (floating) floating.destroy(); } };
    },
});

// -----------------------------------------------------------------------------
// accordion — collapsible sections; single-open by default.
// -----------------------------------------------------------------------------
registerBehavior({
    name: 'platform.accordion',
    ui: 'accordion',
    options: [{ name: 'multiple', type: 'bool', default: false }],
    connect(el, opts, ctx) {
        const cells = [];
        for (const item of ctx.roles('item')) {
            const trigger = item.querySelector('[ui-behavior-toggle]');
            const content = item.querySelector('[ui-behavior-content]');
            if (!trigger || !content) continue;
            cells.push({ trigger, t: useTogglable(content, { trigger, openClass: 'sx-open' }) });
        }
        for (const cell of cells) {
            ctx.on(cell.trigger, 'click', (e) => {
                e.preventDefault();
                if (!opts.multiple && !cell.t.isOpen()) {
                    cells.forEach((c) => { if (c !== cell) c.t.hide(); });
                }
                cell.t.toggle();
            });
        }
        ctx.on(el, 'keydown', (e) => {
            if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
            const trigs = cells.map((c) => c.trigger);
            const i = trigs.indexOf(document.activeElement);
            if (i === -1) return;
            e.preventDefault();
            trigs[e.key === 'ArrowDown' ? (i + 1) % trigs.length : (i - 1 + trigs.length) % trigs.length].focus();
        });
        return {};
    },
});

// -----------------------------------------------------------------------------
// tabs — one panel visible at a time; roving tabindex + arrow-nav.
// -----------------------------------------------------------------------------
registerBehavior({
    name: 'platform.tabs',
    ui: 'tabs',
    options: [{ name: 'active', type: 'number', default: 0 }],
    connect(el, opts, ctx) {
        const tabs = ctx.roles('tab');
        const panels = ctx.roles('panel');
        if (tabs.length === 0) return {};
        function panelFor(i) {
            const controls = tabs[i].getAttribute('aria-controls');
            return (controls && el.querySelector('#' + controls)) || panels[i] || null;
        }
        function select(idx) {
            tabs.forEach((tab, i) => {
                const on = i === idx;
                tab.setAttribute('aria-selected', on ? 'true' : 'false');
                tab.tabIndex = on ? 0 : -1;
                const panel = panelFor(i);
                if (panel) panel.hidden = !on;
            });
            ctx.emit('select', { index: idx });
        }
        tabs.forEach((tab, i) => ctx.on(tab, 'click', (e) => { e.preventDefault(); select(i); tab.focus(); }));
        ctx.on(el, 'keydown', (e) => {
            const i = tabs.indexOf(document.activeElement);
            if (i === -1) return;
            let n = null;
            if (e.key === 'ArrowRight') n = (i + 1) % tabs.length;
            else if (e.key === 'ArrowLeft') n = (i - 1 + tabs.length) % tabs.length;
            else if (e.key === 'Home') n = 0;
            else if (e.key === 'End') n = tabs.length - 1;
            if (n === null) return;
            e.preventDefault(); select(n); tabs[n].focus();
        });
        select(Number(opts.active) || 0);
        return {};
    },
});

// -----------------------------------------------------------------------------
// tooltip — hover/focus label positioned via useFloating; aria-describedby.
// -----------------------------------------------------------------------------
registerBehavior({
    name: 'platform.tooltip',
    ui: 'tooltip',
    options: [
        { name: 'title', type: 'string' },
        { name: 'pos', type: 'enum', default: 'top', values: ['top', 'bottom', 'left', 'right'] },
        { name: 'delay', type: 'number', default: 100 },
    ],
    connect(el, opts, ctx) {
        let tip = ctx.role('content');
        let created = false;
        if (!tip) {
            tip = document.createElement('div');
            tip.setAttribute('ui-behavior-content', '');
            tip.setAttribute('role', 'tooltip');
            tip.textContent = opts.title || '';
            tip.hidden = true;
            document.body.appendChild(tip);
            created = true;
        }
        const id = tip.id || (tip.id = 'sx-tip-' + Math.random().toString(36).slice(2, 8));
        const posMap = { top: 'top-start', bottom: 'bottom-start', left: 'left', right: 'right' };
        let floating = null; let timer = null;
        function show() {
            tip.hidden = false;
            el.setAttribute('aria-describedby', id);
            floating = useFloating(el, tip, { pos: posMap[opts.pos] || 'top-start', offset: 6 });
            requestAnimationFrame(() => tip.classList.add('sx-open'));
        }
        function hide() {
            clearTimeout(timer);
            tip.classList.remove('sx-open');
            el.removeAttribute('aria-describedby');
            if (floating) { floating.destroy(); floating = null; }
            tip.hidden = true;
        }
        ctx.on(el, 'mouseenter', () => { timer = setTimeout(show, opts.delay); });
        ctx.on(el, 'mouseleave', hide);
        ctx.on(el, 'focus', show);
        ctx.on(el, 'blur', hide);
        ctx.on(el, 'keydown', (e) => { if (e.key === 'Escape') hide(); });
        return { destroy() { hide(); if (created && tip.parentNode) tip.remove(); } };
    },
});

// -----------------------------------------------------------------------------
// modal — native <dialog>.showModal() (focus trap + Esc + backdrop for free)
// plus open triggers, scroll-lock, and animated transitions.
// -----------------------------------------------------------------------------
registerBehavior({
    name: 'platform.modal',
    ui: 'modal',
    options: [{ name: 'bgClose', type: 'bool', default: true }],
    connect(el, opts, ctx) {
        const isDialog = typeof el.showModal === 'function';
        const unlock = () => { document.documentElement.style.overflow = ''; };
        function open() {
            if (isDialog) el.showModal(); else { el.hidden = false; el.setAttribute('open', ''); }
            document.documentElement.style.overflow = 'hidden';
            requestAnimationFrame(() => el.classList.add('sx-open'));
            ctx.emit('open', {});
        }
        function close() {
            el.classList.remove('sx-open');
            setTimeout(() => {
                if (isDialog) el.close(); else { el.hidden = true; el.removeAttribute('open'); }
                unlock();
                ctx.emit('close', {});
            }, 150);
        }
        const selfId = el.id ? '#' + el.id : null;
        if (selfId) {
            document.querySelectorAll('[ui-behavior-open]').forEach((t) => {
                if (t.getAttribute('ui-behavior-open') === selfId) {
                    ctx.on(t, 'click', (e) => { e.preventDefault(); open(); });
                }
            });
        }
        ctx.qa('[ui-behavior-dismiss]').forEach((d) => ctx.on(d, 'click', (e) => { e.preventDefault(); close(); }));
        if (isDialog) {
            ctx.on(el, 'cancel', (e) => { e.preventDefault(); close(); }); // Esc
            if (opts.bgClose) ctx.on(el, 'click', (e) => { if (e.target === el) close(); }); // backdrop
        }
        return { open, close, destroy() { unlock(); } };
    },
});

// -----------------------------------------------------------------------------
// offcanvas — slide-in side panel (trap + dismiss + scroll-lock + backdrop).
// -----------------------------------------------------------------------------
registerBehavior({
    name: 'platform.offcanvas',
    ui: 'offcanvas',
    options: [
        { name: 'side', type: 'enum', default: 'start', values: ['start', 'end'] },
        { name: 'bgClose', type: 'bool', default: true },
    ],
    connect(el, opts, ctx) {
        const focus = useFocusTrap(el, { returnTo: null });
        const scroll = useScrollLock();
        const dismiss = useDismiss(el, { onDismiss: () => close(), esc: true, outside: false });
        let backdrop = null; let openState = false;
        el.setAttribute('data-side', opts.side);
        function open() {
            if (openState) return; openState = true;
            el.hidden = false;
            backdrop = document.createElement('div');
            backdrop.className = 'sx-backdrop';
            document.body.appendChild(backdrop);
            scroll.lock();
            requestAnimationFrame(() => { el.classList.add('sx-open'); backdrop.classList.add('sx-open'); });
            focus.activate();
            dismiss.activate();
            if (opts.bgClose) backdrop.addEventListener('click', () => close());
            ctx.emit('open', {});
        }
        function close() {
            if (!openState) return; openState = false;
            el.classList.remove('sx-open');
            if (backdrop) backdrop.classList.remove('sx-open');
            focus.release();
            dismiss.release();
            setTimeout(() => {
                el.hidden = true;
                if (backdrop) { backdrop.remove(); backdrop = null; }
                scroll.unlock();
                ctx.emit('close', {});
            }, 250);
        }
        const selfId = el.id ? '#' + el.id : null;
        if (selfId) {
            document.querySelectorAll('[ui-behavior-open]').forEach((t) => {
                if (t.getAttribute('ui-behavior-open') === selfId) ctx.on(t, 'click', (e) => { e.preventDefault(); open(); });
            });
        }
        ctx.qa('[ui-behavior-dismiss]').forEach((d) => ctx.on(d, 'click', (e) => { e.preventDefault(); close(); }));
        return { open, close, destroy() { scroll.unlock(); if (backdrop) backdrop.remove(); focus.release(); } };
    },
});

// -----------------------------------------------------------------------------
// toast — transient corner notifications (programmatic API + declarative trigger).
// -----------------------------------------------------------------------------
function ensureToastRegion(pos) {
    const id = 'sx-toast-region-' + pos;
    let region = document.getElementById(id);
    if (!region) {
        region = document.createElement('div');
        region.id = id;
        region.className = 'sx-toast-region sx-toast-' + pos;
        region.setAttribute('aria-live', 'polite');
        region.setAttribute('role', 'status');
        document.body.appendChild(region);
    }
    return region;
}
function showToast(message, o) {
    o = o || {};
    const region = ensureToastRegion(o.pos || 'top-end');
    const t = document.createElement('div');
    t.className = 'sx-toast sx-toast-status-' + (o.status || 'info');
    t.textContent = message;
    region.appendChild(t);
    requestAnimationFrame(() => t.classList.add('sx-open'));
    const remove = () => { t.classList.remove('sx-open'); setTimeout(() => { if (t.parentNode) t.remove(); }, 200); };
    const timeout = (o.timeout == null) ? 4000 : o.timeout;
    if (timeout > 0) setTimeout(remove, timeout);
    t.addEventListener('click', remove);
    return remove;
}
if (typeof window !== 'undefined') { window.SemitexaUi = window.SemitexaUi || {}; window.SemitexaUi.toast = showToast; }
registerBehavior({
    name: 'platform.toast',
    ui: 'toast',
    options: [
        { name: 'message', type: 'string' },
        { name: 'status', type: 'enum', default: 'info', values: ['info', 'success', 'warning', 'danger'] },
        { name: 'pos', type: 'enum', default: 'top-end', values: ['top-end', 'top-start', 'bottom-end', 'bottom-start'] },
        { name: 'timeout', type: 'number', default: 4000 },
    ],
    connect(el, opts, ctx) {
        ctx.on(el, 'click', () => showToast(opts.message || (el.textContent || '').trim(), { status: opts.status, pos: opts.pos, timeout: opts.timeout }));
        return {};
    },
});

// -----------------------------------------------------------------------------
// sticky — native position:sticky + a sentinel that toggles a `sx-stuck` class.
// -----------------------------------------------------------------------------
registerBehavior({
    name: 'platform.sticky',
    ui: 'sticky',
    options: [{ name: 'offset', type: 'number', default: 0 }],
    connect(el, opts, ctx) {
        const offset = Number(opts.offset) || 0;
        el.style.position = 'sticky';
        el.style.top = offset + 'px';
        const sentinel = document.createElement('div');
        sentinel.setAttribute('aria-hidden', 'true');
        sentinel.style.cssText = 'position:absolute;height:1px;width:1px;visibility:hidden;pointer-events:none;';
        if (el.parentNode) el.parentNode.insertBefore(sentinel, el);
        const view = useInView(sentinel, {
            threshold: 0,
            rootMargin: (-offset) + 'px 0px 0px 0px',
            onEnter: () => el.classList.remove('sx-stuck'),
            onLeave: () => el.classList.add('sx-stuck'),
            signal: ctx.signal,
        });
        return { destroy() { view.destroy(); if (sentinel.parentNode) sentinel.remove(); el.classList.remove('sx-stuck'); } };
    },
});

// -----------------------------------------------------------------------------
// scrollspy — add a class when the element scrolls into view (reveal-on-enter).
// -----------------------------------------------------------------------------
registerBehavior({
    name: 'platform.scrollspy',
    ui: 'scrollspy',
    options: [
        { name: 'cls', type: 'string', default: 'sx-inview' },
        { name: 'repeat', type: 'bool', default: false },
        { name: 'threshold', type: 'number', default: 0 },
    ],
    connect(el, opts, ctx) {
        const cls = opts.cls || 'sx-inview';
        const view = useInView(el, {
            threshold: Number(opts.threshold) || 0,
            once: !opts.repeat,
            onEnter: () => { el.classList.add(cls); ctx.emit('inview', {}); },
            onLeave: opts.repeat ? () => el.classList.remove(cls) : undefined,
            signal: ctx.signal,
        });
        return { destroy() { view.destroy(); } };
    },
});
