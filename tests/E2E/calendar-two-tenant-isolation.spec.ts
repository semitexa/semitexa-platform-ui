import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';

/**
 * Two-tenant live isolation — the standing proof for the whole tenant chain.
 *
 * The calendar feed is #[TenantScoped] and live-bound (#[WatchScopes
 * 'platform_calendar_events']). The SAME permitted user is signed in on two
 * subdomain tenants (default + demo, by Host), each with the calendar page
 * open: its component subscribes to the feed through HUG and is pushed frames
 * on that page's KISS stream. Both tenants write. Four invariants, each of
 * which has ACTUALLY failed at least once:
 *
 *  1. initial paint is tenant-filtered (the tenant-blind-query audit hole);
 *  2. a write wakes ONLY the writer's tenant stream (channel scoping);
 *  3. the woken stream's RE-RUN executes under the STREAM's tenant — the
 *     re-run used to run under a leftover foreign context in the shared
 *     KISS transport coroutine and served the WRONG tenant's rows
 *     (fixed via ReRunContext tenant capture + RouteReRunner swap +
 *     ContainerTenancyBootstrapper binding);
 *  4. no frame on either stream ever carries the other tenant's rows.
 *
 * One user on both tenants is the strict form: the feed already narrows to
 * the signed-in owner (c7711b8), so only the tenant boundary keeps the two
 * calendars apart.
 *
 * tk-calendar-isolation-e2e-auth: until 2026-10-14 this opened the feed
 * anonymously over a raw SSE door, which the sign-in requirement (c7711b8) and
 * the KISS/HUG-only transport both closed; it sat as a fixme.
 */

test.use({ launchOptions: { args: ['--host-resolver-rules=MAP *.semitexa.test 127.0.0.1'] } });

const CALENDAR_PAGE = '/ui-playground/components/calendar';

function origins(): { default: string; demo: string } {
    const base = new URL(process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:9502');
    return { default: base.origin, demo: `${base.protocol}//demo.dev.semitexa.test:${base.port}` };
}

/** Every calendar data frame the page's KISS stream delivers, as event titles. */
async function recordFrames(context: BrowserContext): Promise<void> {
    await context.addInitScript(() => {
        const Native = window.EventSource;
        (window as unknown as { __calendarFrames: string[][] }).__calendarFrames = [];
        window.EventSource = class extends Native {
            constructor(url: string | URL, init?: EventSourceInit) {
                super(url, init);
                this.addEventListener('ui.collection.data', (ev) => {
                    try {
                        const frame = JSON.parse((ev as MessageEvent).data);
                        if (!Array.isArray(frame.data)) return;
                        (window as unknown as { __calendarFrames: string[][] }).__calendarFrames.push(
                            frame.data.map((row: { title?: string }) => String(row.title ?? '')),
                        );
                    } catch {
                        /* not a data frame */
                    }
                });
            }
        } as typeof EventSource;
    });
}

async function signedInCalendar(browser: Browser, origin: string): Promise<{ context: BrowserContext; page: Page }> {
    const context = await browser.newContext({ baseURL: origin });
    await recordFrames(context);
    const page = await context.newPage();
    await page.goto('/playground/auth/login', { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="username"]').fill('admin');
    await page.locator('input[name="password"]').fill('changeme');
    await Promise.all([
        page.waitForURL(/login=ok/),
        page.locator('[data-ui-component="platform.sign-in"] button[type="submit"]').click(),
    ]);
    return { context, page };
}

/** Wait until no new frame arrives on any of the pages for quietMs (at most maxMs). */
async function quiet(pages: Page[], quietMs = 3_000, maxMs = 15_000): Promise<void> {
    const count = async () => (await Promise.all(pages.map(async (page) => (await frames(page)).length))).join(',');
    const started = Date.now();
    let last = await count();
    let since = Date.now();
    while (Date.now() - started < maxMs && Date.now() - since < quietMs) {
        await new Promise((resolve) => setTimeout(resolve, 250));
        const now = await count();
        if (now !== last) {
            last = now;
            since = Date.now();
        }
    }
    expect(Date.now() - since, 'the streams went quiet before the foreign-row check').toBeGreaterThanOrEqual(quietMs);
}

const frames = (page: Page) => page.evaluate(() => (window as unknown as { __calendarFrames: string[][] }).__calendarFrames);

/** POST to a calendar endpoint the way the runtime does: same origin, CSRF header from the cookie. */
function post(page: Page, path: string, body: object): Promise<{ status: number; json: { event?: { id: string } } | null }> {
    return page.evaluate(async ({ path, body }) => {
        const token = decodeURIComponent((document.cookie.split(/;\s*/).find((c) => c.startsWith('XSRF-TOKEN=')) ?? '').slice(11));
        const res = await fetch(path, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': token },
            body: JSON.stringify(body),
        });
        const text = await res.text();
        let json = null;
        try { json = JSON.parse(text); } catch { /* reported by status */ }
        return { status: res.status, json };
    }, { path, body });
}

test.describe('calendar two-tenant live isolation', () => {
    test('streams are tenant-isolated in data AND in live re-runs', async ({ browser }) => {
        test.setTimeout(90_000);
        const run = Date.now().toString(36);
        const t = (name: string) => `e2e-${run} ${name}`;
        // Inside the month the calendar opens on, so the visible window holds them.
        const month = new Date().toISOString().slice(0, 7);
        const at = (day: string) => `${month}-${day} 10:00:00`;
        const created: Array<{ id: string; page: Page }> = [];
        let passed = false;

        const host = origins();
        const a = await signedInCalendar(browser, host.default);
        const b = await signedInCalendar(browser, host.demo);
        const save = async (page: Page, title: string, startsAt: string) => {
            const res = await post(page, '/platform/calendar/events/save', { title, startsAt, endsAt: startsAt.replace(' 10:', ' 11:') });
            expect(res.status, `save ${title}`).toBeLessThan(300);
            if (res.json?.event?.id) created.push({ id: res.json.event.id, page });
        };

        try {
            // Seed one event per tenant BEFORE the calendars open.
            await save(a.page, t('default-seed'), at('05'));
            await save(b.page, t('demo-seed'), at('05'));

            for (const { page } of [a, b]) {
                await page.goto(CALENDAR_PAGE, { waitUntil: 'domcontentloaded' });
                await expect(page.locator('[data-ui-calendar]')).toBeVisible();
            }

            // 1. Initial paint: each stream sees its own seed and NOT the other's.
            await expect.poll(async () => (await frames(a.page)).flat(), { timeout: 15_000 }).toContain(t('default-seed'));
            await expect.poll(async () => (await frames(b.page)).flat(), { timeout: 15_000 }).toContain(t('demo-seed'));

            // 2+3. Write in DEMO: the demo stream re-runs AS DEMO...
            await save(b.page, t('demo-live'), at('06'));
            await expect.poll(async () => (await frames(b.page)).flat(), { timeout: 15_000 }).toContain(t('demo-live'));

            // ...and write in DEFAULT: the default stream re-runs AS DEFAULT.
            await save(a.page, t('default-live'), at('06'));
            await expect.poll(async () => (await frames(a.page)).flat(), { timeout: 15_000 }).toContain(t('default-live'));

            // 4. No frame on either stream ever carried the other tenant's rows —
            //    this is the assertion that caught the real foreign-context re-run bug.
            //    A leaked frame may arrive after the owner's own, so the check
            //    waits for both streams to go quiet: every new frame on either
            //    one restarts the quiet period.
            await quiet([a.page, b.page]);
            const defaultSeen = (await frames(a.page)).flat();
            const demoSeen = (await frames(b.page)).flat();
            for (const foreign of [t('demo-seed'), t('demo-live')]) expect(defaultSeen).not.toContain(foreign);
            for (const foreign of [t('default-seed'), t('default-live')]) expect(demoSeen).not.toContain(foreign);
            passed = true;
        } finally {
            // Tenant-scoped delete: each row must be deleted AS its owner, on its tenant.
            const leftovers: string[] = [];
            for (const row of created) {
                const res = await post(row.page, '/platform/calendar/events/delete', { id: row.id }).catch(() => ({ status: 0 }));
                if (res.status >= 300 || res.status === 0) leftovers.push(`${row.id} (${res.status})`);
            }
            await a.context.close();
            await b.context.close();
            // Only when the test itself passed: a cleanup failure must not
            // replace the isolation failure that a red run is about.
            if (passed) {
                expect(leftovers, 'every fixture event was deleted by its owner').toEqual([]);
            } else if (leftovers.length > 0) {
                console.warn(`fixture events left behind: ${leftovers.join(', ')}`);
            }
        }
    });
});
