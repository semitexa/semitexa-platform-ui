/**
 * Semitexa Platform UI — shrink photos in the browser before they are sent.
 *
 * A phone photo is 3-5 MB (a 48 MP shot more), and people add a dozen at
 * once: tens of megabytes over a mobile link for images a server cuts down
 * anyway, and refused outright by a field limit or by Swoole's request limit.
 * Found in apartmens on 2026-10-06, where iPhone photos never arrived.
 *
 * `shrinkImage(file, {accept, maxBytes})` decodes the image, redraws it no
 * larger than 2400 px on its long side and re-encodes it as JPEG; with a
 * `maxBytes` it keeps stepping the size down (to 640 px at most) until the
 * photo fits. The browser
 * applies EXIF orientation when it draws; the rest of EXIF, GPS included, is
 * dropped — for a photo going on a public page that is a feature.
 *
 * It FAILS OPEN and never changes what a JPEG cannot hold. The same file comes
 * back when it cannot be decoded, would not get smaller, is already small and
 * in a web format, is a GIF or an SVG (an animation or a vector), has
 * transparency (a logo would be painted white), or when the field does not
 * take JPEG at all (`accept` without image/jpeg or image/*). The server still
 * checks the bytes and decides.
 *
 * Used by the file field (`platform.field`, `control: 'file'`, through
 * upload-runtime) and by the `photo-shrink` behavior on a plain input:
 *
 *   <input type="file" name="photos[]" accept="image/*" multiple ui-behavior="photo-shrink">
 *
 * Global, like the other modules with an import-map name: the map is printed
 * in the head, before a page body could ask for a page-scoped module.
 *
 * A page with its own Content-Security-Policy must allow `img-src blob:`:
 * decoding goes through an object URL.
 */
import { registerBehavior } from 'platform-ui/behaviors';

const MAX_EDGE = 2400;
const QUALITY = 0.86;
// Fitting a field limit steps the size down to no less than this, a little
// softer: past it a photo is no longer worth sending, and the size check says so.
const MIN_EDGE = 640;
const FIT_QUALITY = 0.8;
// Below this a file is cheap to send; redrawing it would only cost quality.
const SMALL_ENOUGH = 1.5 * 1024 * 1024;
const WEB_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
// Never redrawn: an animation would stop, a vector would become pixels.
const KEEP_TYPES = ['image/gif', 'image/svg+xml'];
const MAY_HAVE_ALPHA = ['image/png', 'image/webp', 'image/avif', 'image/heic', 'image/heif', ''];

function takesJpeg(accept) {
    if (!accept || accept.length === 0) return true;
    return accept.some((a) => {
        const t = String(a).trim().toLowerCase();
        return t === 'image/jpeg' || t === 'image/*' || t === '*/*' || t === '.jpg' || t === '.jpeg';
    });
}

function decode(file) {
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = () => resolve({ img, url });
        img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('decode')); };
        img.src = url;
    });
}

function encode(canvas, quality) {
    return new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
}

function hasTransparency(ctx, width, height) {
    const data = ctx.getImageData(0, 0, width, height).data;
    for (let i = 3; i < data.length; i += 4) {
        if (data[i] < 255) return true;
    }
    return false;
}

/**
 * @param {File} file
 * @param {{accept?: string[], maxBytes?: number}} [options]
 * @returns {Promise<File>} a smaller JPEG, or `file` itself
 */
export async function shrinkImage(file, options = {}) {
    const type = (file.type || '').toLowerCase();
    if (type !== '' && !type.startsWith('image/')) return file;
    if (KEEP_TYPES.includes(type)) return file;
    if (!takesJpeg(options.accept)) return file;

    const smallEnough = options.maxBytes > 0 ? Math.min(SMALL_ENOUGH, options.maxBytes) : SMALL_ENOUGH;

    let decoded;
    try {
        decoded = await decode(file);
    } catch (e) {
        return file;
    }
    const { img, url } = decoded;
    let canvas = null;

    try {
        const w = img.naturalWidth;
        const h = img.naturalHeight;
        if (!w || !h) return file;

        const fits = Math.max(w, h) <= MAX_EDGE;
        if (fits && file.size <= smallEnough && WEB_TYPES.includes(type)) return file;

        const scale = fits ? 1 : MAX_EDGE / Math.max(w, h);
        canvas = document.createElement('canvas');
        canvas.width = Math.round(w * scale);
        canvas.height = Math.round(h * scale);
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        if (!ctx) return file;
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        // Read on the scaled canvas: the full-size bitmap is the memory spike
        // that gets a mobile Safari tab killed.
        if (MAY_HAVE_ALPHA.includes(type) && hasTransparency(ctx, canvas.width, canvas.height)) return file;

        let blob = await encode(canvas, QUALITY);

        // A field limit below what a 2400 px photo costs (an avatar at 200 KB)
        // is met by stepping the size down rather than by refusing the photo.
        // Each step redraws from the decoded original, never from a JPEG.
        let edge = Math.max(canvas.width, canvas.height);
        while (blob && options.maxBytes > 0 && blob.size > options.maxBytes && edge > MIN_EDGE) {
            edge = Math.max(MIN_EDGE, Math.round(edge * 0.75));
            const step = edge / Math.max(w, h);
            canvas.width = Math.round(w * step);
            canvas.height = Math.round(h * step);
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            blob = await encode(canvas, FIT_QUALITY);
        }
        if (!blob || blob.size >= file.size) return file;

        const name = (file.name || 'photo').replace(/\.[^.]*$/, '') + '.jpg';

        return new File([blob], name, { type: 'image/jpeg', lastModified: file.lastModified });
    } finally {
        URL.revokeObjectURL(url);
        // Release the bitmap now: iOS caps total canvas memory.
        if (canvas) { canvas.width = 0; canvas.height = 0; }
    }
}

/** One at a time: decoding a dozen 12 MP photos at once is the same spike. */
export async function shrinkImages(files, options = {}) {
    const out = [];
    for (const f of files) out.push(await shrinkImage(f, options));
    return out;
}

// Submit buttons a form's shrinking inputs hold, counted per form so two
// inputs (or two choices) release them only when the last one is done, and
// each button gets back the disabled state the page gave it.
const formHolds = new WeakMap();

function holdSubmitters(form) {
    if (!form) return;
    let hold = formHolds.get(form);
    if (!hold) {
        // form.elements and .type, not a selector: a <button> without a type
        // attribute submits too, and a button outside the form joins it by form="".
        const buttons = Array.from(form.elements).filter((e) => e.type === 'submit' || e.type === 'image');
        hold = { count: 0, before: new Map(buttons.map((b) => [b, b.disabled])) };
        formHolds.set(form, hold);
        buttons.forEach((b) => { b.disabled = true; b.setAttribute('aria-busy', 'true'); });
    }
    hold.count++;
}

function releaseSubmitters(form) {
    const hold = form ? formHolds.get(form) : null;
    if (!hold || --hold.count > 0) return;
    formHolds.delete(form);
    hold.before.forEach((wasDisabled, b) => { b.disabled = wasDisabled; b.removeAttribute('aria-busy'); });
}

registerBehavior({
    name: 'platform.photo-shrink',
    ui: 'photo-shrink',
    options: [],
    connect(el, _opts, ctx) {
        if (typeof DataTransfer === 'undefined' || typeof HTMLCanvasElement === 'undefined') return {};

        const form = el.form;
        const accept = (el.getAttribute('accept') || '').split(',').filter((a) => a.trim() !== '');
        let busy = null;
        // Each choice gets a number; a shrink that finishes after a newer
        // choice, a cleared input or a form reset must not put its files back.
        let current = 0;
        // A change we caused by assigning el.files must not loop.
        let replacing = false;

        function onChange() {
            if (replacing) return;
            const mine = ++current;
            queuedFor = 0;
            if (!el.files || el.files.length === 0) {
                busy = null;
                return;
            }

            const picked = Array.from(el.files);
            holdSubmitters(form);

            const run = shrinkImages(picked, { accept })
                .then((out) => {
                    if (mine !== current || out.every((f, i) => f === picked[i])) return;
                    const dt = new DataTransfer();
                    out.forEach((f) => dt.items.add(f));
                    replacing = true;
                    try {
                        el.files = dt.files;
                        // Assigning files fires nothing, and ui-core's size check
                        // judged the ORIGINALS on the user's change: let it look
                        // again at what is really sent.
                        el.dispatchEvent(new Event('change', { bubbles: true }));
                    } finally {
                        replacing = false;
                    }
                })
                .catch(() => { /* fail open: the originals stay in the input */ })
                .finally(() => {
                    if (mine === current) busy = null;
                    releaseSubmitters(form);
                });
            busy = run;
        }

        // At most one submit waits for a shrink, and only for that shrink: a
        // newer choice, a cleared input or a reset drops it.
        let queuedFor = 0;

        function onReset() {
            current++;
            busy = null;
            queuedFor = 0;
        }

        // Enter in another field can still submit while photos are shrinking:
        // hold that submit until they are done, then send it.
        function onSubmit(e) {
            if (!busy) return;
            e.preventDefault();
            e.stopImmediatePropagation();
            if (queuedFor === current) return;
            const forChoice = current;
            queuedFor = forChoice;
            busy.then(() => {
                if (queuedFor !== forChoice || forChoice !== current) return;
                queuedFor = 0;
                form.requestSubmit();
            });
        }

        ctx.on(el, 'change', onChange);
        // Capture phase, so this runs before a document-level submit handler.
        if (form) {
            ctx.on(form, 'submit', onSubmit, { capture: true });
            ctx.on(form, 'reset', onReset);
        }

        return {};
    },
});
