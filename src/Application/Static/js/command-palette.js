/**
 * Semitexa Platform UI — the command palette (platform.command-palette).
 *
 * A native modal <dialog>: any <button commandfor="<id>" command="show-modal">
 * opens it, and so does Ctrl+K / ⌘K. The search box is a combobox over one
 * listbox with three sections:
 *
 *   recent  — the last commands chosen here (localStorage, this browser only),
 *             shown while the query is empty;
 *   page    — elements marked `data-ui-command="Label"` (optionally
 *             data-ui-command-group / -keywords) and the page's navigation
 *             links, filtered in the browser (every word must match);
 *   (one destination shows once: recent, then page, then server wins)
 *   server  — the component's own `query` part: typing reaches the server
 *             through HUG (signed, session-bound), every #[AsCommandSource]
 *             answers, and a `replace` effect swaps this section. An answer to
 *             a query the visitor has typed past stays hidden.
 *
 * Keys: ArrowUp/ArrowDown move (wrapping), Enter opens, Esc closes (native).
 * A link option is clicked, so the app shell's navigation can take it.
 */
import { mount } from 'platform-ui/core';

const RECENT_KEY = 'sx-command-recent';
const MAX_RECENT = 5;
const MAX_PAGE = 30;
const SAME_ORIGIN_PATH = /^\/(?![/\\])/;
const IS_MAC = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '');

function readRecent() {
    try {
        const list = JSON.parse(window.localStorage.getItem(RECENT_KEY) || '[]');
        return Array.isArray(list)
            ? list.filter((r) => r && typeof r.title === 'string' && typeof r.href === 'string' && SAME_ORIGIN_PATH.test(r.href)).slice(0, MAX_RECENT)
            : [];
    } catch (e) {
        return [];
    }
}

function remember(title, href) {
    try {
        const list = readRecent().filter((r) => r.href !== href);
        list.unshift({ title, href, group: 'Recent' });
        window.localStorage.setItem(RECENT_KEY, JSON.stringify(list.slice(0, MAX_RECENT)));
    } catch (e) { /* storage unavailable: no recent list, nothing else lost */ }
}

let hotkeyOwner = null;

function connect(root) {
    const dialog = root.querySelector('[ui-command-dialog]');
    const input = root.querySelector('[data-ui-part="query"]');
    const list = root.querySelector('[ui-command-list]');
    if (!dialog || !input || !list) return null;
    const recentEl = list.querySelector('[ui-command-section="recent"]');
    const pageEl = list.querySelector('[ui-command-section="page"]');
    const emptyEl = list.querySelector('[ui-command-empty]');
    const instanceId = root.getAttribute('data-ui-component-instance-id');
    const controller = new AbortController();
    const on = (target, type, handler) => target.addEventListener(type, handler, { signal: controller.signal });
    let pageCommands = [];
    let active = -1;

    if (IS_MAC) {
        const modifier = root.querySelector('[ui-command-trigger] [ui="kbd"] > kbd');
        if (modifier) modifier.textContent = '⌘';
    }

    function collect() {
        const out = [];
        const seen = new Set();
        document.querySelectorAll('[data-ui-command]').forEach((el) => {
            if (root.contains(el)) return;
            const title = (el.getAttribute('data-ui-command') || el.textContent || '').trim().replace(/\s+/g, ' ');
            if (title === '') return;
            out.push({ title, group: el.getAttribute('data-ui-command-group') || 'On this page', keywords: el.getAttribute('data-ui-command-keywords') || '', el });
        });
        document.querySelectorAll('nav a[href], [role="navigation"] a[href]').forEach((a) => {
            const href = a.getAttribute('href') || '';
            if (root.contains(a) || !SAME_ORIGIN_PATH.test(href) || seen.has(href)) return;
            const title = (a.getAttribute('aria-label') || a.textContent || '').trim().replace(/\s+/g, ' ');
            if (title === '') return;
            seen.add(href);
            out.push({ title, group: 'Go to', keywords: href, href });
        });
        return out;
    }

    function option(command, id) {
        const o = document.createElement(command.href ? 'a' : 'div');
        o.setAttribute('role', 'option');
        o.setAttribute('ui-command-option', '');
        o.id = id;
        if (command.href) o.setAttribute('href', command.href);
        const title = document.createElement('span');
        title.setAttribute('ui-command-title', '');
        title.textContent = command.title;
        o.appendChild(title);
        if (command.el) o.sxTarget = command.el;
        return o;
    }

    function renderGroups(section, commands, prefix) {
        section.replaceChildren();
        let group = null;
        commands.forEach((command, i) => {
            if (command.group !== group) {
                group = command.group;
                const label = document.createElement('div');
                label.setAttribute('ui-command-group-label', '');
                label.setAttribute('role', 'presentation');
                label.textContent = group;
                section.appendChild(label);
            }
            section.appendChild(option(command, dialog.id + '-' + prefix + i));
        });
    }

    const query = () => input.value.trim().slice(0, 100);
    const serverEl = () => list.querySelector('[data-ui-patch-target="server-results"]');
    const serverAnswered = () => { const s = serverEl(); return s !== null && (s.getAttribute('data-query') || '') === query(); };

    function render() {
        const q = query().toLowerCase();
        const terms = q.split(/\s+/).filter(Boolean);
        renderGroups(recentEl, q === '' ? readRecent() : [], 'r');
        const matching = terms.length === 0 ? pageCommands : pageCommands.filter((c) => {
            const hay = (c.title + ' ' + c.group + ' ' + c.keywords).toLowerCase();
            return terms.every((t) => hay.includes(t));
        });
        renderGroups(pageEl, matching.slice(0, MAX_PAGE), 'p');
        settle();
    }

    // One destination, one option: a page reached from the recent list, the
    // page's navigation and a server source shows once — its first section wins.
    function dedupe() {
        const seen = new Set();
        list.querySelectorAll('[role="option"]').forEach((o) => {
            o.hidden = false;
            if (o.closest('[hidden]')) return;
            const href = o.getAttribute('href');
            if (!href) return;
            if (seen.has(href)) o.hidden = true;
            else seen.add(href);
        });
    }

    function options() {
        return Array.from(list.querySelectorAll('[role="option"]')).filter((o) => !o.closest('[hidden]'));
    }

    function settle() {
        const s = serverEl();
        if (s) s.hidden = !serverAnswered();
        dedupe();
        const opts = options();
        emptyEl.hidden = !(query() !== '' && opts.length === 0 && serverAnswered());
        select(opts.length > 0 ? 0 : -1);
    }

    function select(index) {
        const opts = options();
        opts.forEach((o) => o.setAttribute('aria-selected', 'false'));
        active = opts.length === 0 ? -1 : (index + opts.length) % opts.length;
        if (active < 0) { input.removeAttribute('aria-activedescendant'); return; }
        opts[active].setAttribute('aria-selected', 'true');
        input.setAttribute('aria-activedescendant', opts[active].id);
        opts[active].scrollIntoView({ block: 'nearest' });
    }

    function choose(o) {
        if (o.sxTarget) {
            dialog.close();
            o.sxTarget.click();
            return;
        }
        o.click(); // a link: the shell's navigation (or the browser) takes it
    }

    function opened() {
        pageCommands = collect();
        input.value = '';
        render();
        input.focus();
    }

    // The dialog opens natively (commandfor) or from the hotkey: watch `open`.
    const observer = new MutationObserver(() => { if (dialog.open) opened(); });
    observer.observe(dialog, { attributes: true, attributeFilter: ['open'] });

    on(input, 'input', render);
    on(input, 'keydown', (e) => {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            select(active + (e.key === 'ArrowDown' ? 1 : -1));
        } else if (e.key === 'Enter') {
            const o = options()[active];
            if (o) { e.preventDefault(); choose(o); }
        }
    });
    on(list, 'mousemove', (e) => {
        const o = e.target.closest('[role="option"]');
        if (o) { const i = options().indexOf(o); if (i !== active) select(i); }
    });
    on(list, 'click', (e) => {
        const o = e.target.closest('[role="option"]');
        if (!o) return;
        if (o.sxTarget) { e.preventDefault(); choose(o); return; }
        const href = o.getAttribute('href');
        if (href && SAME_ORIGIN_PATH.test(href)) {
            remember((o.querySelector('[ui-command-title]') || o).textContent.trim(), href);
            dialog.close();
        }
    });
    // The server section arrived (a `replace` effect on this instance).
    on(document, 'semitexa:ui-patch:applied', (e) => {
        const patch = e.detail && e.detail.patch;
        if (patch && patch.target && patch.target.instance === instanceId && patch.target.name === 'server-results') settle();
    });

    // One palette on the page owns Ctrl+K. Every hotkey palette listens, and
    // the owner acts; when the owner is torn down (a navigation swapped its
    // region), the next palette to see the key takes it over instead of the
    // hotkey going dead until a reload.
    if (root.hasAttribute('data-ui-command-hotkey')) {
        if (hotkeyOwner === null) hotkeyOwner = root;
        on(document, 'keydown', (e) => {
            if (!((e.ctrlKey || e.metaKey) && !e.altKey && e.key.toLowerCase() === 'k')) return;
            if (hotkeyOwner === null || !hotkeyOwner.isConnected) hotkeyOwner = root;
            if (hotkeyOwner !== root) return;
            e.preventDefault();
            if (dialog.open) dialog.close(); else dialog.showModal();
        });
    }

    return {
        destroy() {
            controller.abort();
            observer.disconnect();
            if (hotkeyOwner === root) hotkeyOwner = null;
        },
    };
}

mount('[data-ui-command-palette]', { connect });
