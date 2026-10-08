import { expect, test, type Page } from '@playwright/test';

/**
 * The UI Workbench, driven through the whole catalog.
 *
 * Every entry whose #[AsUiContract] declares examples is opened in light and
 * dark; each example must render something, pass the accessibility rules
 * below, and match its screenshot baseline. Behaviors are then exercised with
 * the keyboard, because behavior JS has hidden bugs that every server-side
 * test passed (the dropdown's dead first 400 ms, a focus restore to <body>).
 *
 * The a11y rules are checked in the page rather than through axe-core, which
 * the E2E runner image does not ship: accessible names on every control,
 * id references that resolve, unique ids, and WCAG 2 text contrast against
 * the composited background. They are the rules the kit's own bugs broke.
 *
 * The Workbench is a dev surface (APP_ENV=dev or PLATFORM_UI_WORKBENCH=1).
 * Where it is closed the suite is reported as skipped, not passed.
 */

type Entry = { name: string; examples: number };

const MODES = ['light', 'dark'] as const;

async function catalog(page: Page): Promise<Entry[]> {
    const response = await page.goto('/__ui/workbench', { waitUntil: 'domcontentloaded' });
    test.skip(response?.status() === 404, 'UI Workbench is closed here (needs APP_ENV=dev or PLATFORM_UI_WORKBENCH=1)');
    expect(response?.status()).toBe(200);
    return page.locator('[data-workbench-link]').evaluateAll((links) =>
        links.map((a) => ({
            name: a.getAttribute('data-workbench-link') ?? '',
            examples: Number(a.querySelector('.wb-pill:not(.wb-pill-muted)')?.textContent ?? '0'),
        })),
    );
}

/** Runs inside the page: returns a list of human-readable violations for one stage. */
function auditStage(stage: Element): string[] {
    const problems: string[] = [];
    const doc = stage.ownerDocument;

    const nameOf = (el: Element): string => {
        const labelledby = el.getAttribute('aria-labelledby');
        if (labelledby) {
            return labelledby.split(/\s+/).map((id) => doc.getElementById(id)?.textContent ?? '').join(' ').trim();
        }
        const label = el.getAttribute('aria-label') ?? el.getAttribute('title') ?? '';
        if (label.trim()) return label.trim();
        if (el instanceof HTMLInputElement || el instanceof HTMLSelectElement || el instanceof HTMLTextAreaElement) {
            const wrapping = el.closest('label');
            const forLabel = el.id ? doc.querySelector(`label[for="${CSS.escape(el.id)}"]`) : null;
            return ((forLabel ?? wrapping)?.textContent ?? el.getAttribute('placeholder') ?? '').trim();
        }
        return (el.textContent ?? '').trim();
    };

    for (const el of stage.querySelectorAll('button, a[href], input, select, textarea, [role="button"], [role="tab"], [role="menuitem"]')) {
        if (el.closest('[hidden]') || (el as HTMLElement).offsetParent === null && !(el.closest('dialog'))) continue;
        if (nameOf(el) === '') problems.push(`no accessible name: <${el.tagName.toLowerCase()} ${el.getAttribute('ui') ?? ''}>`);
    }

    for (const attr of ['aria-controls', 'aria-labelledby', 'aria-describedby']) {
        for (const el of stage.querySelectorAll(`[${attr}]`)) {
            for (const id of (el.getAttribute(attr) ?? '').split(/\s+/).filter(Boolean)) {
                if (!doc.getElementById(id)) problems.push(`${attr}="${id}" points at nothing`);
            }
        }
    }

    // WCAG 2 contrast of visible text against the composited background.
    // Computed colours come back in whatever space they were written in
    // (`color-mix(in oklab, …)` stays oklab), so every colour is resolved to
    // sRGB by painting it — a colour that cannot be resolved is a violation,
    // never a skip.
    const canvas = doc.createElement('canvas');
    canvas.width = canvas.height = 1;
    const ctx2d = canvas.getContext('2d', { willReadFrequently: true })!;
    const toRgba = (c: string): [number, number, number, number] | null => {
        if (!c || c === 'transparent') return [0, 0, 0, 0];
        ctx2d.clearRect(0, 0, 1, 1);
        ctx2d.fillStyle = '#010203';
        ctx2d.fillStyle = c;
        if (ctx2d.fillStyle === '#010203' && !/^#010203$/i.test(c)) return null;
        ctx2d.fillRect(0, 0, 1, 1);
        const [r, g, b, a] = ctx2d.getImageData(0, 0, 1, 1).data;
        return [r, g, b, a / 255];
    };
    const lum = ([r, g, b]: number[]): number => {
        const f = (v: number) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
        return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
    };
    const backgroundOf = (el: Element | null): number[] | null => {
        const layers: [number, number, number, number][] = [];
        for (let n = el; n; n = n.parentElement) {
            const c = toRgba(getComputedStyle(n).backgroundColor);
            if (c === null) return null;
            if (c[3] > 0) { layers.push(c); if (c[3] >= 1) break; }
        }
        let rgb = [255, 255, 255];
        for (const [r, g, b, a] of layers.reverse()) rgb = [r * a + rgb[0] * (1 - a), g * a + rgb[1] * (1 - a), b * a + rgb[2] * (1 - a)];
        return rgb;
    };
    const effectiveOpacity = (el: Element): number => {
        let o = 1;
        for (let n: Element | null = el; n; n = n.parentElement) o *= Number(getComputedStyle(n).opacity);
        return o;
    };
    const check = (el: Element, text: string): void => {
        const style = getComputedStyle(el);
        const fg = toRgba(style.color);
        const bg = backgroundOf(el);
        if (fg === null || bg === null) { problems.push(`unresolvable colour for "${text.slice(0, 24)}"`); return; }
        const alpha = fg[3] * effectiveOpacity(el);
        const blended = [fg[0] * alpha + bg[0] * (1 - alpha), fg[1] * alpha + bg[1] * (1 - alpha), fg[2] * alpha + bg[2] * (1 - alpha)];
        const [hi, lo] = [lum(blended), lum(bg)].sort((a, b) => b - a);
        const ratio = (hi + 0.05) / (lo + 0.05);
        const size = parseFloat(style.fontSize);
        const large = size >= 24 || (size >= 18.66 && Number(style.fontWeight) >= 700);
        if (ratio < (large ? 3 : 4.5)) problems.push(`contrast ${ratio.toFixed(2)}:1 for "${text.slice(0, 24)}"`);
    };
    const visible = (el: Element): boolean => {
        const style = getComputedStyle(el);
        return style.visibility !== 'hidden' && effectiveOpacity(el) > 0.05 && el.getClientRects().length > 0;
    };
    const walker = doc.createTreeWalker(stage, NodeFilter.SHOW_TEXT);
    for (let t = walker.nextNode(); t; t = walker.nextNode()) {
        const text = (t.textContent ?? '').trim();
        const el = t.parentElement;
        if (!text || !el || el.closest('[hidden], [aria-hidden="true"], :disabled, [aria-disabled="true"]')) continue;
        if (el.closest('[ui-state="loading"]')) continue; // label is transparent by design while busy
        if (!visible(el)) continue;
        check(el, text);
    }
    // A field's value is text too, but not a text node.
    for (const input of stage.querySelectorAll('input:not([type="hidden"]), textarea')) {
        const value = (input as HTMLInputElement).value.trim();
        if (value && !(input as HTMLInputElement).disabled && visible(input)) check(input, value);
    }
    return problems;
}

test.describe('platform-ui · UI Workbench', () => {
    test('every catalog entry with examples renders them, accessibly, in light and dark', async ({ page }) => {
        test.setTimeout(240_000);
        const entries = (await catalog(page)).filter((e) => e.examples > 0);
        expect(entries.length).toBeGreaterThan(20);

        const errors: string[] = [];
        page.on('pageerror', (e) => errors.push(e.message));
        // Collected across the whole catalog and asserted once, so one run
        // reports every violation instead of stopping at the first.
        const violations: string[] = [];

        for (const entry of entries) {
            for (const mode of MODES) {
                await page.goto(`/__ui/workbench?entry=${entry.name}&mode=${mode}`, { waitUntil: 'networkidle' });
                const examples = page.locator('[data-workbench-example]');
                await expect(examples, `${entry.name} examples`).toHaveCount(entry.examples);

                for (const example of await examples.all()) {
                    const id = await example.getAttribute('data-workbench-example');
                    const stage = example.locator('[data-workbench-stage]');
                    // Rendered by the real runtime: never an empty stage.
                    expect(await stage.evaluate((s) => s.children.length), `${entry.name}/${id} renders`).toBeGreaterThan(0);
                    const problems = await stage.evaluate(auditStage);
                    violations.push(...problems.map((p) => `${entry.name}/${id} (${mode}): ${p}`));

                    // A behavior's content only exists on screen once opened:
                    // open it, then audit what appeared (menus, panels,
                    // dialogs, tooltips, toasts) the same way.
                    const openers = stage.locator('[ui-behavior-toggle], [ui-behavior-open], [ui-behavior="toast"], [ui-behavior="toggle"], [ui-behavior-tab]');
                    const count = await openers.count();
                    if (count > 0) {
                        for (let i = 0; i < count; i++) {
                            const opener = openers.nth(i);
                            if (await opener.isVisible()) { await opener.click(); }
                        }
                        const tip = stage.locator('[ui-behavior="tooltip"]');
                        if (await tip.count() > 0) await tip.first().focus();
                        await page.waitForTimeout(350);
                        const opened = await page.evaluate(([audit, exampleId]) => {
                            const fn = new Function('return ' + audit)() as (e: Element) => string[];
                            const roots = [...document.querySelectorAll('dialog[open], [ui-behavior~="offcanvas"].sx-open, [role="tooltip"].sx-open, .sx-toast-region')];
                            const own = document.querySelector(`[data-workbench-example="${exampleId}"] [data-workbench-stage]`);
                            return [...roots, ...(own ? [own] : [])].flatMap((r) => fn(r));
                        }, [auditStage.toString(), id ?? ''] as const);
                        violations.push(...[...new Set(opened)].map((p) => `${entry.name}/${id} opened (${mode}): ${p}`));
                        await page.keyboard.press('Escape');
                        await page.evaluate(() => {
                            document.querySelectorAll('dialog[open]').forEach((d) => (d as HTMLDialogElement).close());
                            document.querySelectorAll('.sx-toast').forEach((t) => t.remove());
                        });
                    }
                }

                const ids = await page.evaluate(() => {
                    const seen = new Map<string, number>();
                    document.querySelectorAll('[id]').forEach((n) => seen.set(n.id, (seen.get(n.id) ?? 0) + 1));
                    return [...seen].filter(([, n]) => n > 1).map(([id]) => id);
                });
                violations.push(...ids.map((id) => `${entry.name} (${mode}): duplicate id ${id}`));
            }
        }
        expect(violations).toEqual([]);
        expect(errors).toEqual([]);
    });

    test('every example matches its screenshot baseline', async ({ page }) => {
        test.setTimeout(300_000);
        const entries = (await catalog(page)).filter((e) => e.examples > 0);
        await page.setViewportSize({ width: 1280, height: 900 });
        for (const entry of entries) {
            for (const mode of MODES) {
                await page.goto(`/__ui/workbench?entry=${entry.name}&mode=${mode}&skin=default`, { waitUntil: 'networkidle' });
                for (const example of await page.locator('[data-workbench-example]').all()) {
                    const id = await example.getAttribute('data-workbench-example');
                    await expect(example.locator('[data-workbench-stage]')).toHaveScreenshot(
                        `${entry.name.replace('platform.', '')}-${id}-${mode}.png`,
                        { animations: 'disabled', caret: 'hide', maxDiffPixels: 0, threshold: 0.1 },
                    );
                }
            }
        }
    });

    test('dropdown is a keyboard menu that returns focus', async ({ page }) => {
        await catalog(page);
        await page.goto('/__ui/workbench?entry=platform.dropdown', { waitUntil: 'networkidle' });
        const root = page.locator('[data-workbench-example="menu"] [ui-behavior="dropdown"]');
        const trigger = root.locator('[ui-behavior-toggle]');
        await expect(trigger).toHaveAttribute('aria-haspopup', 'menu');

        await trigger.focus();
        await page.keyboard.press('ArrowDown');
        await expect(root.locator('[role="menu"]')).toBeVisible();
        await expect(root.locator('[role="menuitem"]').first()).toBeFocused();
        await page.keyboard.press('End');
        await expect(root.locator('[role="menuitem"]').last()).toBeFocused();
        await page.keyboard.press('d');
        await expect(root.locator('[role="menuitem"]', { hasText: 'Duplicate' })).toBeFocused();
        await page.keyboard.press('Escape');
        await expect(trigger).toBeFocused();
        await expect(trigger).toHaveAttribute('aria-expanded', 'false');
    });

    test('tabs move with arrows and keep one panel visible', async ({ page }) => {
        await catalog(page);
        await page.goto('/__ui/workbench?entry=platform.tabs', { waitUntil: 'networkidle' });
        const tabs = page.locator('[data-workbench-example="underline"] [role="tab"]');
        await expect(page.locator('[data-workbench-example="underline"] [role="tablist"]')).toHaveCount(1);
        await tabs.first().focus();
        await page.keyboard.press('ArrowRight');
        await expect(tabs.nth(1)).toBeFocused();
        await expect(tabs.nth(1)).toHaveAttribute('aria-selected', 'true');
        await expect(page.locator('[data-workbench-example="underline"] [role="tabpanel"]:visible')).toHaveCount(1);
    });

    test('accordion toggles its region and reports it', async ({ page }) => {
        await catalog(page);
        await page.goto('/__ui/workbench?entry=platform.accordion', { waitUntil: 'networkidle' });
        const first = page.locator('[data-workbench-example="single"] [ui-behavior-toggle]').first();
        await expect(first).toHaveAttribute('aria-expanded', 'false');
        await first.click();
        await expect(first).toHaveAttribute('aria-expanded', 'true');
        const region = page.locator(`#${await first.getAttribute('aria-controls')}`);
        await expect(region).toBeVisible();
        await expect(region).toHaveAttribute('role', 'region');
    });

    test('modal traps focus, closes on Escape, and a toast raised inside it works', async ({ page }) => {
        await catalog(page);
        await page.goto('/__ui/workbench?entry=platform.modal', { waitUntil: 'networkidle' });
        const opener = page.locator('[data-workbench-example="form"] [ui-behavior-open]');
        await opener.click();
        const dialog = page.locator('[data-workbench-example="form"] dialog');
        await expect(dialog).toBeVisible();
        await page.evaluate(() => (window as unknown as { SemitexaUi: { toast: (m: string, o: object) => void } }).SemitexaUi.toast('Saved', { status: 'success', timeout: 0 }));
        const toast = page.locator('dialog .sx-toast');
        await expect(toast).toBeVisible();
        await toast.locator('.sx-toast-close').click();
        await expect(toast).toHaveCount(0);
        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
    });
});
