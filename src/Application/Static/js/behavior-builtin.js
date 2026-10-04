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

// ARIA wiring: the server markup names the parts (ui-behavior-tab, -panel, …);
// the runtime fills in the roles and id references the author would otherwise
// have to write by hand. An attribute the author already set is never replaced.
let sxUid = 0;
function ensureId(node, prefix) {
    if (!node.id) node.id = prefix + '-' + (++sxUid).toString(36) + Math.random().toString(36).slice(2, 6);
    return node.id;
}
function setIfMissing(node, name, value) {
    if (!node.hasAttribute(name)) node.setAttribute(name, value);
}

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
            // Keyboard parity for mouseleave: close once focus leaves both the
            // trigger and the revealed content (relatedTarget null => focus lost).
            ctx.on(el, 'focusout', (e) => {
                if (!el.contains(e.relatedTarget) && !target.contains(e.relatedTarget)) t.hide();
            });
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
        const dismiss = useDismiss(el, { onDismiss: () => close(true), esc: true, outside: true });

        // A panel of [ui-behavior-item]s is a menu (WAI-ARIA menu button). Any
        // other panel (a form, a picker) keeps its own semantics. Items of a
        // behavior nested in the panel (an accordion) are not this menu's.
        const ownItems = () => ctx.roles('item').filter((i) => i.closest('[ui-behavior]') === el);
        const isMenu = ownItems().length > 0;
        ensureId(content, 'sx-dd');
        setIfMissing(trigger, 'aria-controls', content.id);
        setIfMissing(trigger, 'aria-expanded', 'false');
        if (isMenu) {
            setIfMissing(trigger, 'aria-haspopup', 'menu');
            setIfMissing(content, 'role', 'menu');
            for (const item of ownItems()) {
                setIfMissing(item, 'role', 'menuitem');
                setIfMissing(item, 'tabindex', '-1');
            }
        }
        const visibleItems = () => ownItems().filter((i) => i.offsetParent !== null && !i.hasAttribute('disabled') && i.getAttribute('aria-disabled') !== 'true');

        function open(focusLast) {
            if (togglable.isOpen()) return;
            // Reveal, position, and wire interaction SYNCHRONOUSLY — dismissal
            // and positioning must be live the instant the panel appears, never
            // gated behind the reveal transition.
            content.hidden = false;
            floating = useFloating(trigger, content, { pos: opts.pos, offset: opts.offset, flip: opts.flip });
            dismiss.activate();
            ctx.emit('open', {});
            togglable.show(); // aria-expanded + open flag + reveal transition (fire-and-forget)
            const items = visibleItems();
            if (items.length) (focusLast ? items[items.length - 1] : items[0]).focus();
        }
        // restoreFocus: Esc, outside click and choosing an item return focus to
        // the trigger; Tab lets focus move on naturally (a menu is not a trap).
        function close(restoreFocus) {
            if (!togglable.isOpen()) return;
            dismiss.release();
            if (floating) { floating.destroy(); floating = null; }
            ctx.emit('close', {});
            togglable.hide(); // reverse transition, then hidden (fire-and-forget)
            if (restoreFocus && content.contains(document.activeElement)) trigger.focus();
            else if (restoreFocus === true && document.activeElement === document.body) trigger.focus();
        }

        if (opts.mode === 'hover') {
            ctx.on(el, 'mouseenter', () => open(false));
            ctx.on(el, 'mouseleave', () => close(false));
        } else {
            ctx.on(trigger, 'click', (e) => { e.preventDefault(); togglable.isOpen() ? close(true) : open(false); });
        }
        ctx.on(trigger, 'keydown', (e) => {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); open(e.key === 'ArrowUp'); }
        });

        const editable = (t) => t instanceof Element && t.closest('input, textarea, select, [contenteditable=""], [contenteditable="true"]') !== null;
        ctx.on(content, 'keydown', (e) => {
            // Only a menu closes on Tab; a form in a panel keeps normal tabbing.
            if (!isMenu) return;
            if (e.key === 'Tab') { close(false); return; }
            // A filter box inside a menu keeps its letters and Home/End.
            if (editable(e.target)) return;
            const items = visibleItems();
            if (items.length === 0) return;
            const i = items.indexOf(document.activeElement);
            let n = null;
            if (e.key === 'ArrowDown') n = (i + 1) % items.length;
            else if (e.key === 'ArrowUp') n = (i - 1 + items.length) % items.length;
            else if (e.key === 'Home') n = 0;
            else if (e.key === 'End') n = items.length - 1;
            else if (e.key.length === 1 && /\S/.test(e.key)) {
                // Typeahead: jump to the next item starting with the typed letter.
                const k = e.key.toLowerCase();
                for (let step = 1; step <= items.length; step++) {
                    const j = (i + step) % items.length;
                    if ((items[j].textContent || '').trim().toLowerCase().startsWith(k)) { n = j; break; }
                }
            }
            if (n === null) return;
            e.preventDefault();
            items[n].focus();
        });
        if (isMenu) {
            ctx.on(content, 'click', (e) => {
                const item = e.target.closest('[ui-behavior-item]');
                if (item && content.contains(item)) close(true);
            });
        }

        return { destroy() { dismiss.release(); if (floating) floating.destroy(); } };
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
            ensureId(trigger, 'sx-acc-t');
            ensureId(content, 'sx-acc-p');
            setIfMissing(trigger, 'aria-controls', content.id);
            setIfMissing(trigger, 'aria-expanded', content.hidden ? 'false' : 'true');
            setIfMissing(content, 'role', 'region');
            setIfMissing(content, 'aria-labelledby', trigger.id);
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
            const trigs = cells.map((c) => c.trigger);
            const i = trigs.indexOf(document.activeElement);
            if (i === -1) return;
            let n = null;
            if (e.key === 'ArrowDown') n = (i + 1) % trigs.length;
            else if (e.key === 'ArrowUp') n = (i - 1 + trigs.length) % trigs.length;
            else if (e.key === 'Home') n = 0;
            else if (e.key === 'End') n = trigs.length - 1;
            if (n === null) return;
            e.preventDefault();
            trigs[n].focus();
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
        const list = ctx.role('list') || tabs[0].parentElement;
        if (list && list !== el) setIfMissing(list, 'role', 'tablist');
        tabs.forEach((tab, i) => {
            setIfMissing(tab, 'role', 'tab');
            ensureId(tab, 'sx-tab');
            const panel = tab.getAttribute('aria-controls') ? null : panels[i];
            if (panel) {
                ensureId(panel, 'sx-tabpanel');
                tab.setAttribute('aria-controls', panel.id);
            }
        });
        panels.forEach((panel, i) => {
            setIfMissing(panel, 'role', 'tabpanel');
            setIfMissing(panel, 'tabindex', '0');
            if (tabs[i]) setIfMissing(panel, 'aria-labelledby', tabs[i].id);
        });
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
        // Share the ref-counted scroll lock so nested overlays (a dropdown/
        // offcanvas opened from within a modal) don't unlock the page early.
        const scroll = useScrollLock();
        function open() {
            if (isDialog) el.showModal(); else { el.hidden = false; el.setAttribute('open', ''); }
            scroll.lock();
            requestAnimationFrame(() => el.classList.add('sx-open'));
            ctx.emit('open', {});
        }
        function close() {
            el.classList.remove('sx-open');
            setTimeout(() => {
                if (isDialog) el.close(); else { el.hidden = true; el.removeAttribute('open'); }
                scroll.unlock();
                ctx.emit('close', {});
            }, ctx.reduceMotion ? 0 : 150);
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
        return { open, close, destroy() { scroll.unlock(); } };
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
            }, ctx.reduceMotion ? 0 : 250);
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
    // A modal <dialog> makes everything outside it inert — a toast there would
    // show but its close button would not work. Host the region in the
    // topmost open modal, else in <body>.
    const modals = document.querySelectorAll('dialog[open]');
    let host = document.body;
    for (const d of modals) { if (d.matches(':modal')) host = d; }
    if (region && region.parentNode !== host) { region.remove(); region = null; }
    if (!region) {
        region = document.createElement('div');
        region.id = id;
        region.className = 'sx-toast-region sx-toast-' + pos;
        region.setAttribute('aria-live', 'polite');
        region.setAttribute('role', 'status');
        region.setAttribute('popover', 'manual');
        host.appendChild(region);
    }
    // Top layer, and topmost: re-showing moves it above a dialog opened since.
    if (typeof region.showPopover === 'function') {
        try {
            if (region.matches(':popover-open')) region.hidePopover();
            region.showPopover();
        } catch (e) { /* not connected yet, or popover unsupported: stays a fixed layer */ }
    }
    return region;
}
// Lucide-shaped status glyphs (static markup, never user input).
const TOAST_ICONS = {
    info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
    success: '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
    warning: '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4M12 17h.01"/>',
    danger: '<circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>',
};
function showToast(message, o) {
    o = o || {};
    const status = TOAST_ICONS[o.status] ? o.status : 'info';
    const region = ensureToastRegion(o.pos || 'top-end');
    const t = document.createElement('div');
    t.className = 'sx-toast sx-toast-status-' + status;
    t.setAttribute('ui-tone', status);
    // Errors interrupt; everything else waits its turn in the polite region.
    if (status === 'danger') t.setAttribute('role', 'alert');

    const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    icon.setAttribute('class', 'sx-toast-icon');
    icon.setAttribute('viewBox', '0 0 24 24');
    icon.setAttribute('aria-hidden', 'true');
    icon.innerHTML = TOAST_ICONS[status];

    const body = document.createElement('div');
    body.className = 'sx-toast-body';
    if (o.title) {
        const title = document.createElement('div');
        title.className = 'sx-toast-title';
        title.textContent = String(o.title);
        body.appendChild(title);
    }
    const text = document.createElement('div');
    text.className = 'sx-toast-message';
    text.textContent = String(message);
    body.appendChild(text);

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'sx-toast-close';
    close.setAttribute('aria-label', o.closeLabel || 'Dismiss');
    close.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>';

    t.append(icon, body, close);
    region.appendChild(t);
    requestAnimationFrame(() => t.classList.add('sx-open'));

    let timer = null;
    let hovered = false;
    const remove = () => {
        clearTimeout(timer);
        t.classList.remove('sx-open');
        setTimeout(() => { if (t.parentNode) t.remove(); }, 200);
    };
    const timeout = (o.timeout == null) ? 4000 : o.timeout;
    // Reading a toast must not race its timer: it waits while hovered or focused.
    const arm = () => {
        clearTimeout(timer);
        if (timeout > 0 && !hovered && !t.contains(document.activeElement)) timer = setTimeout(remove, timeout);
    };
    arm();
    t.addEventListener('mouseenter', () => { hovered = true; clearTimeout(timer); });
    t.addEventListener('mouseleave', () => { hovered = false; arm(); });
    t.addEventListener('focusin', () => clearTimeout(timer));
    t.addEventListener('focusout', () => setTimeout(arm, 0));
    // Clicking the toast dismisses it, as before; the close button is the accessible way.
    t.addEventListener('click', remove);
    return remove;
}
if (typeof window !== 'undefined') { window.SemitexaUi = window.SemitexaUi || {}; window.SemitexaUi.toast = showToast; }
registerBehavior({
    name: 'platform.toast',
    ui: 'toast',
    options: [
        { name: 'message', type: 'string' },
        { name: 'title', type: 'string' },
        { name: 'status', type: 'enum', default: 'info', values: ['info', 'success', 'warning', 'danger'] },
        { name: 'pos', type: 'enum', default: 'top-end', values: ['top-end', 'top-start', 'bottom-end', 'bottom-start'] },
        { name: 'timeout', type: 'number', default: 4000 },
    ],
    connect(el, opts, ctx) {
        ctx.on(el, 'click', () => showToast(opts.message || (el.textContent || '').trim(), { title: opts.title, status: opts.status, pos: opts.pos, timeout: opts.timeout }));
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
