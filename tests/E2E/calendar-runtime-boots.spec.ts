import { expect, test } from '@playwright/test';

/**
 * The calendar RUNTIME, in a browser.
 *
 * The sibling spec (`calendar-two-tenant-isolation`) opens raw SSE streams
 * against the feed endpoint: it proves the server isolates tenants and touches
 * none of `calendar-runtime.js`. So when that transport moved onto
 * `openFeedChannel`, nothing in the suite would have noticed the client break.
 *
 * The only shell in this repository that renders `[data-ui-calendar]` is the OS
 * Calendar app, and it sets `data-ui-calendar-live="0"` — the single-user OS
 * calendar opts out of the held-open stream because that loop's blocking Redis
 * read can deadlock a Swoole worker. So the path that actually runs here is the
 * plain JSON pull, and that is what this pins: the shell boots, the runtime
 * renders a month grid into it, and no stream is opened.
 */
const EMAIL = process.env.CMS_E2E_EMAIL ?? 'owner@semitexa.test';
const PASSWORD = process.env.CMS_E2E_PASSWORD ?? 'walkthrough-2026';

async function signIn(page: import('@playwright/test').Page): Promise<void> {
    await page.goto('/os/login', { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="email"]').fill(EMAIL);
    await page.locator('input[name="password"]').fill(PASSWORD);
    await Promise.all([
        page.waitForLoadState('domcontentloaded'),
        page.locator('form button[type="submit"], form input[type="submit"]').first().click(),
    ]);
}

test.describe('platform-ui · calendar runtime', () => {
    test('the shell is rendered into by the runtime, over a plain pull', async ({ page }) => {
        const streams: string[] = [];
        page.on('request', (request) => {
            if (request.url().includes('/platform/calendar/events') && request.resourceType() === 'eventsource') {
                streams.push(request.url());
            }
        });

        await signIn(page);
        await page.goto('/os/app/calendar', { waitUntil: 'domcontentloaded' });

        const shell = page.locator('[data-ui-calendar]');
        await expect(shell).toHaveAttribute('data-ui-calendar-live', '0');

        // The month grid exists only if calendar-runtime.js booted, fetched the
        // window and called render() — an empty shell would still be visible.
        await expect(page.locator('.uical__head')).toBeVisible();
        await expect(page.locator('.uical__body')).toBeVisible();
        await expect(page.locator('[data-cal="today"]')).toBeVisible();

        // Month navigation re-reads the window and re-renders.
        const title = (await page.locator('.uical__head').innerText()).trim();
        await page.locator('[data-cal="prev"]').click();
        await expect
            .poll(async () => (await page.locator('.uical__head').innerText()).trim())
            .not.toBe(title);

        expect(streams, 'live="0" must not open a held-open stream').toEqual([]);
    });
});
