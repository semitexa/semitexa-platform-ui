/**
 * Semitexa Platform UI — the navigation authority.
 *
 * ONE client owns the URL. A console ends up with two swap layers otherwise:
 * one that re-renders a REGION of the page it is on (a filter, a sort, a page
 * of results) and one that replaces the whole working area (the main menu).
 * Both push history, both listen to popstate, and neither can see the other.
 * What that costs was measured on a real console: the region layer had to
 * learn the path it was bound to and bail out when a history step changed it,
 * the page layer had to announce every swap on the document so the region
 * layer could forget what it remembered, and LISTENER ORDER decided
 * correctness — the region script loads first, so it sees popstate first,
 * which put the guard in the layer it does not belong to. All of that is
 * arbitration between two halves of one concern, hand-written, in every
 * project that gets this far.
 *
 * So this module decides, and tells the layers. A region layer registers
 * itself with `registerRegion()` and is asked first: a region move WINS over a
 * page move for the same click, because it is the cheaper move. Everything
 * else is a page move, and every failure on either path hands the URL back to
 * the browser rather than swallowing it.
 *
 * It does nothing at all on a page that marks no region, which is every page
 * that has not opted in.
 */

const SHELL_HEADER = 'X-Semitexa-Shell';
const REGION_ATTR = 'data-shell-region';

/**
 * A script arriving inside a swapped region is NOT inert.
 *
 * Parsed into a <template> and then inserted, the browser tries to run it —
 * carrying the nonce of the response it was PARSED from, which is not this
 * document's, so a strict CSP refuses it. The page then works (the re-created
 * copy below runs) while reporting a blocked script on every single swap.
 *
 * The answer is to make it non-executable BEFORE it enters the document and
 * restore the real type, with this document's nonce, after.
 */
const INERT_TYPE = 'application/x-semitexa-inert';
const ORIGINAL_TYPE = 'data-semitexa-original-type';

const EXECUTABLE_TYPES = ['', 'text/javascript', 'application/javascript', 'module', 'importmap'];

/** Region layers, asked in registration order. */
const regionHandlers = [];

/**
 * The URL this client last COMMITTED to — its intent, which is not the same
 * thing as what is on screen.
 *
 * Back pressed before a page has landed compares the new URL against the page
 * still showing, decides nothing changed, and leaves the console sitting under
 * a foreign address. Comparing against intent is what makes that impossible.
 */
let committedUrl = currentUrl();

/** Rising token: a response for anything but the latest navigation is dropped. */
let navigationToken = 0;

function currentUrl() {
    return window.location.pathname + window.location.search;
}

function sameOrigin(url) {
    try {
        return new URL(url, window.location.href).origin === window.location.origin;
    } catch (e) {
        return false;
    }
}

function toPathAndQuery(url) {
    const parsed = new URL(url, window.location.href);
    return parsed.pathname + parsed.search;
}

function isShellPage() {
    return document.querySelector('[' + REGION_ATTR + ']') !== null;
}

/**
 * This document's CSP nonce.
 *
 * `getAttribute('nonce')` is empty by design after parse — the browser hides
 * it so injected markup cannot read it back. The IDL property still carries
 * it, and a `<meta name="csp-nonce">` is the documented fallback for a host
 * that publishes it deliberately.
 */
function documentNonce() {
    const meta = document.querySelector('meta[name="csp-nonce"]');
    if (meta && meta.content) return meta.content;
    const script = document.querySelector('script[nonce]');
    return (script && script.nonce) || '';
}

function isExecutableScript(el) {
    const type = (el.getAttribute('type') || '').toLowerCase().trim();
    return EXECUTABLE_TYPES.indexOf(type) !== -1;
}

/** Parse a region's HTML and neutralise every script it carries. */
function prepareRegion(html) {
    const template = document.createElement('template');
    template.innerHTML = html.trim();
    const element = template.content.firstElementChild;
    if (!element) return null;

    element.querySelectorAll('script').forEach((script) => {
        if (!isExecutableScript(script)) return;
        script.setAttribute(ORIGINAL_TYPE, script.getAttribute('type') || '');
        script.setAttribute('type', INERT_TYPE);
    });

    return element;
}

/** Re-create the neutralised scripts so they run, with THIS document's nonce. */
function activateScripts(root) {
    const nonce = documentNonce();

    root.querySelectorAll('script[type="' + INERT_TYPE + '"]').forEach((inert) => {
        const script = document.createElement('script');

        for (const attribute of Array.from(inert.attributes)) {
            if (attribute.name === 'type' || attribute.name === ORIGINAL_TYPE) continue;
            script.setAttribute(attribute.name, attribute.value);
        }

        const original = inert.getAttribute(ORIGINAL_TYPE) || '';
        if (original !== '') script.setAttribute('type', original);
        if (nonce !== '') script.setAttribute('nonce', nonce);

        script.textContent = inert.textContent;
        inert.replaceWith(script);
    });
}

/**
 * Add stylesheets and modules this page needs and the last one did not.
 *
 * CSS is awaited — briefly — because swapping markup in before its rules
 * arrive is a frame of unstyled page, which reads as a bug rather than as a
 * navigation. The timeout is there so a stylesheet that never loads delays the
 * swap by an instant instead of forever.
 */
function ensureAssets(assets) {
    if (!assets) return Promise.resolve();

    // The server says what each script IS. A module added as a classic script
    // is a syntax error the first time it imports anything, and a classic
    // script added as a module changes its scope and its timing — and the URL
    // says nothing about which one it is.
    (assets.js || []).forEach((asset) => {
        const src = typeof asset === 'string' ? asset : asset.src;
        const type = typeof asset === 'string' ? '' : (asset.type || '');
        if (!src || document.querySelector('script[src="' + cssEscape(src) + '"]')) return;

        const script = document.createElement('script');
        script.src = src;
        if (type !== '') script.setAttribute('type', type);
        else script.defer = true;

        const nonce = documentNonce();
        if (nonce !== '') script.setAttribute('nonce', nonce);
        document.body.appendChild(script);
    });

    const pending = (assets.css || [])
        .filter((href) => !document.querySelector('link[rel="stylesheet"][href="' + cssEscape(href) + '"]'))
        .map((href) => new Promise((resolve) => {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = href;
            link.addEventListener('load', resolve, { once: true });
            link.addEventListener('error', resolve, { once: true });
            document.head.appendChild(link);
            setTimeout(resolve, 1000);
        }));

    return Promise.all(pending);
}

function cssEscape(value) {
    return String(value).replace(/"/g, '\\"');
}

function announce(title) {
    let live = document.getElementById('semitexa-nav-announcer');
    if (!live) {
        live = document.createElement('div');
        live.id = 'semitexa-nav-announcer';
        live.setAttribute('aria-live', 'polite');
        live.setAttribute('aria-atomic', 'true');
        // Visually hidden, still announced.
        live.style.cssText = 'position:absolute;width:1px;height:1px;margin:-1px;padding:0;'
            + 'overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0';
        document.body.appendChild(live);
    }
    live.textContent = title || document.title;
}

/**
 * A reload announces itself to a screen reader and resets focus. A swap
 * announces nothing at all and leaves focus in the menu it was clicked from,
 * whose surroundings silently became a different page.
 */
function restoreFocus(region, title) {
    if (region) {
        if (!region.hasAttribute('tabindex')) region.setAttribute('tabindex', '-1');
        try {
            region.focus({ preventScroll: true });
        } catch (e) {
            region.focus();
        }
    }
    announce(title);
}

/**
 * pushState and the DOM move in ONE synchronous block.
 *
 * Either order apart is a window in which the address bar and the markup
 * disagree, and both windows are long enough to be caught by a test and by a
 * person.
 */
function commit(payload, mode) {
    const prepared = {};
    Object.keys(payload.regions || {}).forEach((name) => {
        const element = prepareRegion(payload.regions[name]);
        if (element) prepared[name] = element;
    });

    if (Object.keys(prepared).length === 0) return null;

    const state = { semitexaShell: true, url: payload.url, scroll: 0 };

    if (mode === 'push') {
        saveScroll();
        window.history.pushState(state, '', payload.url);
    } else if (mode === 'replace') {
        window.history.replaceState(state, '', payload.url);
    }

    let firstRegion = null;
    Object.keys(prepared).forEach((name) => {
        const target = document.querySelector('[' + REGION_ATTR + '="' + cssEscape(name) + '"]');
        if (!target) return;
        target.replaceWith(prepared[name]);
        if (!firstRegion) firstRegion = prepared[name];
    });

    if (payload.title) document.title = payload.title;
    committedUrl = payload.url;

    return firstRegion;
}

function saveScroll() {
    const state = window.history.state || {};
    try {
        window.history.replaceState(
            Object.assign({}, state, { scroll: window.scrollY }),
            '',
            currentUrl(),
        );
    } catch (e) {
        // A history that refuses to be written is not a reason to refuse the
        // navigation; the offset is a convenience, the move is not.
    }
}

/**
 * NO `Accept` HEADER, and it cost a debugging round to learn why.
 *
 * The framework already content-negotiates: `Accept: application/json` on a
 * page route answers with that page's JSON REPRESENTATION — iri, meta,
 * alternates — which is a different resource with a different meaning. Asking
 * for JSON because the shell envelope happens to be JSON got that instead, the
 * payload did not carry `shell: true`, and every click fell back to a full
 * navigation. It looked like it worked: the page changed, the URL changed, and
 * nothing in the console said the swap had not happened.
 *
 * The shell header alone selects the shape. Sending an Accept that means
 * something else to the server is how a client asks the wrong question
 * politely.
 */
function fetchShell(url) {
    return fetch(url, {
        credentials: 'same-origin',
        headers: { [SHELL_HEADER]: '1' },
    }).then((response) => {
        if (!response.ok) return null;
        const type = response.headers.get('content-type') || '';
        if (type.indexOf('application/json') === -1) return null;
        return response.json();
    }).then((payload) => {
        if (!payload || payload.shell !== true) return null;
        return payload;
    }).catch(() => null);
}

/** Hand the URL back to the browser. Visibly ordinary beats invisibly stuck. */
function fallback(url, replace) {
    if (replace) window.location.replace(url);
    else window.location.assign(url);
}

/**
 * A region layer: something that can re-render part of the page for a URL
 * without replacing the working area.
 *
 * `matches(url)` says whether this URL is its business; `apply(url)` does the
 * work and resolves true when it did. Registering is how a layer stops owning
 * history for itself — it no longer pushes, no longer listens to popstate, and
 * no longer has to know what the page layer is doing.
 */
export function registerRegion(handler) {
    if (!handler || typeof handler.matches !== 'function' || typeof handler.apply !== 'function') {
        throw new TypeError('a region handler needs matches(url) and apply(url)');
    }
    regionHandlers.push(handler);
    return () => {
        const at = regionHandlers.indexOf(handler);
        if (at !== -1) regionHandlers.splice(at, 1);
    };
}

function regionHandlerFor(url) {
    for (const handler of regionHandlers) {
        let owns = false;
        try {
            owns = handler.matches(url) === true;
        } catch (e) {
            owns = false;
        }
        if (owns) return handler;
    }
    return null;
}

/**
 * Move to a URL. The single entry point: a click, a form, a region layer
 * asking for the address to change — all of it arrives here.
 */
export function navigate(url, options) {
    const opts = options || {};
    const target = toPathAndQuery(url);
    const token = ++navigationToken;

    // A region move wins: it is the cheaper move, and a page swap that also
    // fires would throw away the region's own work.
    const region = regionHandlerFor(target);
    if (region && opts.allowRegion !== false) {
        return Promise.resolve()
            .then(() => region.apply(target))
            .then((applied) => {
                if (applied === false) return pageMove(target, token, opts);

                // The same arbitration the page path has always had. A region
                // apply is asynchronous, so two quick filter clicks can resolve
                // out of order; without this the slower FIRST one lands last
                // and leaves the address bar on a request nobody is looking at.
                if (token !== navigationToken) return false;

                if (opts.history !== false) {
                    saveScroll();
                    window.history.pushState({ semitexaShell: true, url: target, scroll: 0 }, '', target);
                    committedUrl = target;
                }
                return true;
            })
            .catch(() => {
                fallback(target, false);
                return false;
            });
    }

    return pageMove(target, token, opts);
}

function pageMove(url, token, opts) {
    return fetchShell(url).then((payload) => {
        if (token !== navigationToken) return false;

        if (!payload) {
            fallback(url, opts.replace === true);
            return false;
        }

        // The server said what this page loads and the last one did not, and
        // that answer was being thrown away: the markup went in without its
        // stylesheet or its runtime, and the page stayed half-dressed until
        // someone reloaded. Awaited BEFORE the swap, which is the whole reason
        // ensureAssets waits on CSS at all.
        return ensureAssets(payload.assets).then(() => applyPage(url, payload, token, opts));
    });
}

function applyPage(url, payload, token, opts) {
    // Re-checked after the await: a click during asset loading starts a newer
    // navigation, and committing this one on top of it is the stale write the
    // token exists to prevent.
    if (token !== navigationToken) return false;

    const mode = opts.history === false ? 'none' : (opts.replace === true ? 'replace' : 'push');
    const region = commit(payload, mode);

    if (region === null) {
        fallback(url, opts.replace === true);
        return false;
    }

    activateScripts(document);
    window.scrollTo(0, opts.scroll || 0);
    restoreFocus(region, payload.title);

    document.dispatchEvent(new CustomEvent('semitexa:navigation:committed', {
        detail: { url: payload.url, title: payload.title, regions: Object.keys(payload.regions || {}) },
    }));

    return true;
}

function isPlainLeftClick(event) {
    return event.button === 0
        && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey
        && !event.defaultPrevented;
}

function linkFrom(event) {
    const anchor = event.target && event.target.closest ? event.target.closest('a[href]') : null;
    if (!anchor) return null;
    if (anchor.target && anchor.target !== '' && anchor.target !== '_self') return null;
    if (anchor.hasAttribute('download')) return null;
    if (anchor.getAttribute('data-nav') === 'off') return null;
    if (!sameOrigin(anchor.href)) return null;

    const parsed = new URL(anchor.href, window.location.href);

    // ANY fragment link is the browser's job, not ours — on this page or the
    // next one. Intercepting `/reports#totals` used to navigate to `/reports`
    // and drop the `#totals` on the floor: no scroll, no focus, and no
    // `:target` rule matching, which a swap cannot reproduce because `:target`
    // is a property of the URL the browser itself resolved. A full navigation
    // to an anchor is what happened before this module existed and is still
    // right; swallowing the fragment silently is not.
    if (parsed.hash) return null;

    return parsed;
}

function onClick(event) {
    if (!isPlainLeftClick(event)) return;

    const parsed = linkFrom(event);
    if (!parsed) return;

    event.preventDefault();
    navigate(parsed.pathname + parsed.search);
}

/**
 * A history step is judged against INTENT — the URL this client last committed
 * to — not against what is on screen.
 */
function onPopState(event) {
    const url = currentUrl();
    if (url === committedUrl) return;

    const state = (event && event.state) || {};
    const scroll = typeof state.scroll === 'number' ? state.scroll : 0;

    const region = regionHandlerFor(url);
    if (region) {
        // Bumped here too. A page move already in flight passes its own token
        // check otherwise, and commits a page on top of the region this step
        // asked for.
        const regionToken = ++navigationToken;
        committedUrl = url;
        Promise.resolve()
            .then(() => region.apply(url))
            .then((applied) => {
                if (regionToken !== navigationToken) return;
                if (applied === false) {
                    fallback(url, true);
                    return;
                }
                // scrollRestoration is manual, so nobody else will do this, and
                // a history entry that comes back at the previous page's offset
                // is the thing manual mode was turned on to avoid.
                window.scrollTo(0, scroll);
            })
            .catch(() => fallback(url, true));
        return;
    }

    const token = ++navigationToken;
    pageMove(url, token, { history: false, scroll: scroll });
}

function boot() {
    if (!isShellPage()) return;

    // The browser restores the offset before the new markup exists, which puts
    // the page at a position that belonged to the last one.
    if ('scrollRestoration' in window.history) {
        window.history.scrollRestoration = 'manual';
    }

    window.history.replaceState(
        Object.assign({}, window.history.state || {}, { semitexaShell: true, url: committedUrl, scroll: window.scrollY }),
        '',
        committedUrl,
    );

    document.addEventListener('click', onClick);
    window.addEventListener('popstate', onPopState);
}

export const SemitexaNavigation = {
    navigate,
    registerRegion,
    isShellPage,
    get committedUrl() {
        return committedUrl;
    },
};

if (typeof window !== 'undefined') {
    window.SemitexaNavigation = SemitexaNavigation;
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}
