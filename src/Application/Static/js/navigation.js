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

/** An asset that never answers delays the swap by an instant rather than forever. */
const ASSET_TIMEOUT_MS = 1000;

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

/**
 * Re-create the neutralised scripts so they run, with THIS document's nonce.
 *
 * IN DOCUMENT ORDER, and awaiting each EXTERNAL one, because a region can
 * carry a `src` script and the inline script after it usually depends on it.
 * Firing them all off and walking on ran the inline code — and dispatched
 * `semitexa:navigation:committed` — before the file it needs had arrived, so
 * the page looked committed and behaved as though nothing had loaded.
 *
 * Resolves false when an external script fails, which the caller turns into a
 * real navigation: a region whose code cannot load is not a region this client
 * can deliver.
 */
function activateScripts(root) {
    const nonce = documentNonce();
    const inerts = Array.from(root.querySelectorAll('script[type="' + INERT_TYPE + '"]'));

    return inerts.reduce((chain, inert) => chain.then((ok) => {
        const script = document.createElement('script');

        for (const attribute of Array.from(inert.attributes)) {
            if (attribute.name === 'type' || attribute.name === ORIGINAL_TYPE) continue;
            script.setAttribute(attribute.name, attribute.value);
        }

        const original = inert.getAttribute(ORIGINAL_TYPE) || '';
        if (original !== '') script.setAttribute('type', original);
        if (nonce !== '') script.setAttribute('nonce', nonce);

        script.textContent = inert.textContent;

        // An inline script runs the moment it is inserted, so there is nothing
        // to wait for and no load event coming.
        if (!script.src) {
            inert.replaceWith(script);
            return ok;
        }

        return new Promise((resolve) => {
            script.addEventListener('load', () => resolve(ok), { once: true });
            script.addEventListener('error', () => resolve(false), { once: true });
            inert.replaceWith(script);
            setTimeout(() => resolve(false), ASSET_TIMEOUT_MS);
        });
    }), Promise.resolve(true));
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
    // TRUE, not undefined. This resolves to "did everything load", and the
    // caller falls back to a real navigation on false — so an envelope with no
    // assets at all would have sent every swap to the browser.
    if (!assets) return Promise.resolve(true);

    const pending = [];

    // The server says what each script IS. A module added as a classic script
    // is a syntax error the first time it imports anything, and a classic
    // script added as a module changes its scope and its timing — and the URL
    // says nothing about which one it is.
    //
    // Scripts are AWAITED like the stylesheets. Appending and walking on
    // resolves as soon as the CSS is in, so the page committed and announced
    // itself while the runtime that drives it was still downloading — the
    // markup arrives, nothing binds to it, and the console says nothing.
    (assets.js || []).forEach((asset) => {
        const src = typeof asset === 'string' ? asset : asset.src;
        const type = typeof asset === 'string' ? '' : (asset.type || '');
        const attrs = (asset && asset.attrs) || {};
        if (!src || document.querySelector('script[src="' + cssEscape(src) + '"]')) return;

        pending.push(new Promise((resolve) => {
            const script = document.createElement('script');
            script.src = src;
            if (type !== '') script.setAttribute('type', type);
            else if (!('defer' in attrs) && !('async' in attrs)) script.defer = true;

            // integrity, crossorigin, defer/async, nomodule: the tag is
            // RE-CREATED here, so anything the server does not name is lost —
            // an unchecked file where an integrity hash was required, or a
            // script that runs at a different moment than it did on a reload.
            Object.keys(attrs).forEach((name) => script.setAttribute(name, attrs[name]));

            // This document's nonce, never the one the envelope came from.
            const nonce = documentNonce();
            if (nonce !== '') script.setAttribute('nonce', nonce);

            script.addEventListener('load', () => resolve(true), { once: true });
            script.addEventListener('error', () => resolve(false), { once: true });
            document.body.appendChild(script);
            // FALSE on timeout, unlike a stylesheet's. A script that has not
            // arrived is a page whose behaviour is missing, and committing it
            // anyway announces a navigation nothing will bind to.
            setTimeout(() => resolve(false), ASSET_TIMEOUT_MS);
        }));
    });

    (assets.css || [])
        .map((entry) => (typeof entry === 'string' ? { href: entry, attrs: {} } : entry))
        .filter((entry) => entry && entry.href
            && !document.querySelector('link[rel="stylesheet"][href="' + cssEscape(entry.href) + '"]'))
        .forEach((entry) => pending.push(new Promise((resolve) => {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = entry.href;
            const attrs = entry.attrs || {};
            Object.keys(attrs).forEach((name) => link.setAttribute(name, attrs[name]));
            link.addEventListener('load', () => resolve(true), { once: true });
            link.addEventListener('error', () => resolve(true), { once: true });
            document.head.appendChild(link);
            setTimeout(() => resolve(true), ASSET_TIMEOUT_MS);
        })));

    // false ONLY for a script that errored. A stylesheet that will not load
    // costs the page its looks; a script that will not load costs it its
    // behaviour, and a swap is the wrong way to deliver that.
    return Promise.all(pending).then((results) => results.every(Boolean));
}

/**
 * A value made safe to put inside a quoted attribute selector.
 *
 * Escaping only the quote was not enough: the envelope's region names and
 * asset URLs come off the wire with no restricted grammar, and a value ending
 * in a backslash escapes the CLOSING quote instead — `querySelector()` then
 * throws a SyntaxError that nothing on the commit path catches, so navigation
 * stopped dead with no browser fallback.
 *
 * `CSS.escape` is the browser's own answer and is used when present; the
 * replacement below is the same rule for the two characters that can break
 * out, for hosts that lack it.
 */
function cssEscape(value) {
    const text = String(value);

    if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') {
        return CSS.escape(text);
    }

    return text.replace(/\\/g, '\\\\').replace(/"/g, '\\"');
}

let announceTimer = null;

/**
 * Say where we are, including when we have just been here.
 *
 * A live region announces a CHANGE to its contents. Writing the same string
 * twice is not a change, so the second navigation to a page with the same
 * title — paging through a list, reapplying a filter — was silent for anyone
 * listening, which is the case that most needs saying. Cleared first and
 * written back in a later frame, it is a change every time.
 */
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

    const message = title || document.title;

    // An older restore still pending would otherwise write the PREVIOUS page's
    // title over this one, in the frame after this call.
    if (announceTimer !== null) clearTimeout(announceTimer);

    live.textContent = '';
    announceTimer = setTimeout(() => {
        announceTimer = null;
        live.textContent = message;
    }, 50);
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
    const names = Object.keys(payload.regions || {});
    const prepared = {};

    // EVERY region prepared before anything moves, for the same reason every
    // target is resolved below. Dropping the one that would not parse — empty
    // html, text with no element in it — and continuing pushed history and
    // replaced the REST, so the page ended up part from here and part from
    // there and the swap reported success. One failure means nothing done,
    // and the caller hands the URL to the browser.
    for (const name of names) {
        const element = prepareRegion(payload.regions[name]);
        if (!element) return null;
        prepared[name] = element;
    }

    if (names.length === 0) return null;

    // And every target resolved, for the same reason: a layout change can
    // rename or drop a region, and skipping the missing one replaced the
    // others anyway.
    const targets = {};
    for (const name of Object.keys(prepared)) {
        const target = document.querySelector('[' + REGION_ATTR + '="' + cssEscape(name) + '"]');
        if (!target) return null;
        targets[name] = target;
    }

    const state = { semitexaShell: true, url: payload.url, scroll: 0 };

    if (mode === 'push') {
        saveScroll();
        window.history.pushState(state, '', payload.url);
    } else if (mode === 'replace') {
        window.history.replaceState(state, '', payload.url);
    }

    let firstRegion = null;
    Object.keys(prepared).forEach((name) => {
        const target = targets[name];
        target.replaceWith(prepared[name]);
        if (!firstRegion) firstRegion = prepared[name];
    });

    if (payload.title) document.title = payload.title;
    applyDeferredManifest(payload.deferredManifest);
    committedUrl = payload.url;

    return firstRegion;
}

/**
 * Replace the deferred-slot manifest with the arriving page's.
 *
 * It is emitted at body end, outside every marked region, so a swap that
 * carried only regions left the new page's skeletons bound to the PREVIOUS
 * page's request id, session and bind token. They waited for frames addressed
 * to a request that had already finished — no error, no frame, nothing in the
 * console, just skeletons that never resolve.
 *
 * Removed when the destination defers nothing, so a stale manifest cannot
 * outlive the page it belonged to.
 */
function applyDeferredManifest(json) {
    const selector = 'script[type="application/json"][data-ssr-deferred-manifest]';
    const existing = document.querySelector(selector);

    if (!json) {
        if (existing) existing.remove();
        return;
    }

    const block = document.createElement('script');
    block.type = 'application/json';
    block.setAttribute('data-ssr-deferred-manifest', '');
    block.textContent = json;

    // A data block is not governed by script-src, so it needs no nonce — and
    // it is replaced rather than edited so anything memoising the ELEMENT sees
    // a new one and re-reads it.
    if (existing) existing.replaceWith(block);
    else document.body.appendChild(block);
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
                    // `replace` means the same thing here as on the page path,
                    // and always pushing gave a caller that asked to REWRITE
                    // the current entry — a region layer rewriting its own
                    // filter URL — a new one instead. Back then returned to
                    // the same page with different region state, once per
                    // filter the visitor touched.
                    const state = { semitexaShell: true, url: target, scroll: 0 };
                    if (opts.replace === true) {
                        window.history.replaceState(state, '', target);
                    } else {
                        saveScroll();
                        window.history.pushState(state, '', target);
                    }
                    committedUrl = target;
                }
                return true;
            })
            .catch(() => {
                // Guarded like the success path above: fallback() is a real
                // navigation, so an older region apply failing late would send
                // the browser to a URL the client has already moved on from.
                if (token !== navigationToken) return false;
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
        // ensureAssets waits at all.
        return ensureAssets(payload.assets).then((loaded) => {
            if (token !== navigationToken) return false;

            if (!loaded) {
                // A destination whose script will not load is a page this
                // client cannot deliver. Hand it to the browser rather than
                // commit markup nothing will bind to.
                fallback(url, opts.replace === true);
                return false;
            }

            return applyPage(url, payload, token, opts);
        });
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

    // The swap itself is done and history already matches it, so the scroll
    // and the focus move now; only the ANNOUNCEMENT waits for the region's own
    // scripts, because that is what page runtimes listen for.
    window.scrollTo(0, opts.scroll || 0);
    restoreFocus(region, payload.title);

    return activateScripts(document).then((ok) => {
        if (!ok) {
            fallback(url, opts.replace === true);
            return false;
        }

        document.dispatchEvent(new CustomEvent('semitexa:navigation:committed', {
            detail: { url: payload.url, title: payload.title, regions: Object.keys(payload.regions || {}) },
        }));

        return true;
    });
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
            .catch(() => {
                if (regionToken !== navigationToken) return;
                fallback(url, true);
            });
        return;
    }

    const token = ++navigationToken;
    // REPLACE on the fallback: the browser has already traversed to this URL,
    // so assign() would push a second entry for a position history is already
    // sitting on, and Back would then land where the visitor just was.
    pageMove(url, token, { history: false, replace: true, scroll: scroll });
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
