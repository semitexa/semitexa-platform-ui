/**
 * Semitexa Platform UI — the sidebar application shell
 * (@platform-ui/layouts/app-shell.html.twig).
 *
 * The layout works without this file: the drawer is a native popover, the
 * groups are <details name>, the current page is marked by the server. This
 * adds what needs a browser:
 *
 *   - the collapse button: an icon rail on a wide shell, remembered in
 *     localStorage (applied before paint by the layout's head script);
 *   - after an app-shell swap (`semitexa:navigation:committed` — only <main>
 *     changed), the sidebar marks the new current page and opens its group;
 *   - choosing a link in the drawer closes the drawer;
 *   - a cross-document view transition to a page outside the app is skipped.
 */
import { mount } from 'platform-ui/core';

const STORAGE_KEY = 'sx-app-sidebar';

function setCollapsed(collapsed, button) {
    const root = document.documentElement;
    if (collapsed) root.setAttribute('data-app-sidebar', 'collapsed');
    else root.removeAttribute('data-app-sidebar');
    if (button) {
        button.setAttribute('aria-pressed', String(collapsed));
        button.setAttribute('title', collapsed ? 'Expand the sidebar' : 'Collapse the sidebar');
    }
    try {
        if (collapsed) window.localStorage.setItem(STORAGE_KEY, 'collapsed');
        else window.localStorage.removeItem(STORAGE_KEY);
    } catch (e) { /* storage unavailable: the choice lasts for this page */ }
}

function markCurrent(sidebar) {
    const path = window.location.pathname;
    let currentGroup = null;
    sidebar.querySelectorAll('[ui-app-nav-link]').forEach((link) => {
        const href = link.getAttribute('href') || '';
        const prefix = link.getAttribute('data-match') === 'prefix';
        // A prefix matches at a "/" boundary, as the layout does on the server:
        // /admin/article is not current on /admin/articles.
        const current = href === path || (prefix && path.startsWith(href.replace(/\/+$/, '') + '/'));
        if (current) {
            link.setAttribute('aria-current', 'page');
            if (currentGroup === null) currentGroup = link.closest('details');
        } else {
            link.removeAttribute('aria-current');
        }
    });
    if (currentGroup && !currentGroup.open) currentGroup.open = true;
}

function isOpenDrawer(sidebar) {
    try { return sidebar.matches(':popover-open'); } catch (e) { return false; } // no popover support
}

function connect(sidebar) {
    const controller = new AbortController();
    const on = (target, type, handler) => target.addEventListener(type, handler, { signal: controller.signal });
    const collapse = sidebar.querySelector('[ui-app-collapse]');
    if (collapse) {
        collapse.setAttribute('aria-pressed', String(document.documentElement.getAttribute('data-app-sidebar') === 'collapsed'));
        on(collapse, 'click', () => setCollapsed(document.documentElement.getAttribute('data-app-sidebar') !== 'collapsed', collapse));
    }
    on(sidebar, 'click', (e) => {
        const link = e.target.closest('a[href]');
        if (link && isOpenDrawer(sidebar)) sidebar.hidePopover();
    });
    on(document, 'semitexa:navigation:committed', () => markCurrent(sidebar));

    return { destroy() { controller.abort(); } };
}

// A cross-document view transition needs BOTH documents to opt in; a link out
// of the app (appHome) leads to a page that may not, and the browser then
// reports the skipped transition as an uncaught error there. Skip it here.
if (typeof window !== 'undefined') {
    window.addEventListener('pageswap', (e) => {
        const transition = e.viewTransition;
        const entry = e.activation && e.activation.entry;
        if (!transition || !entry) return;
        const home = (document.body && document.body.getAttribute('data-app-home')) || '/';
        let to;
        try { to = new URL(entry.url); } catch (err) { to = null; }
        const inside = to !== null && to.origin === window.location.origin && (home === '/' || to.pathname === home || to.pathname.startsWith(home.replace(/\/$/, '') + '/'));
        if (inside) return;
        // Skipping rejects the transition's promises: nobody awaits them.
        [transition.ready, transition.finished, transition.updateCallbackDone].forEach((p) => { if (p && p.catch) p.catch(() => {}); });
        transition.skipTransition();
    });
}

mount('[ui-app-sidebar]', { connect });
