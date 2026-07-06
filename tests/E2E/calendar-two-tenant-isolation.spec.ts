import { test, expect } from '@playwright/test';
import * as http from 'node:http';

/**
 * Two-tenant SSE isolation — the standing proof for the whole tenant chain.
 *
 * The calendar feed is #[TenantScoped] and live-bound (#[WatchScopes
 * 'platform_calendar_events']). Two subdomain tenants (default + demo, via
 * the Host header) each hold an open SSE stream while both tenants write.
 * Four invariants, each of which has ACTUALLY failed at least once:
 *
 *  1. initial paint is tenant-filtered (the tenant-blind-query audit hole);
 *  2. a write wakes ONLY the writer's tenant stream (channel scoping);
 *  3. the woken stream's RE-RUN executes under the STREAM's tenant — the
 *     re-run used to run under a leftover foreign context in the shared
 *     KISS transport coroutine and served the WRONG tenant's rows
 *     (fixed via ReRunContext tenant capture + RouteReRunner swap +
 *     ContainerTenancyBootstrapper binding);
 *  4. no frame on either stream ever carries the other tenant's rows.
 */

const DEMO_HOST = 'demo.dev.semitexa.test';
const RANGE = 'from=2036-01-01&to=2036-01-31';

/** Node http (undici's fetch forbids overriding Host, which is the whole point). */
type SseCapture = { frames: Array<{ count: number; titles: string[] }>; close: () => void };

function baseUrl(): URL {
    return new URL(process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:9502');
}

function openStream(host: string | null): Promise<SseCapture> {
    const base = baseUrl();
    return new Promise((resolve, reject) => {
        const req = http.request(
            {
                hostname: base.hostname,
                port: base.port,
                path: `/platform/calendar/events?${RANGE}`,
                headers: {
                    Accept: 'text/event-stream',
                    ...(host ? { Host: host } : {}),
                },
            },
            (res) => {
                const capture: SseCapture = { frames: [], close: () => req.destroy() };
                let buffer = '';
                res.setEncoding('utf8');
                res.on('data', (chunk: string) => {
                    buffer += chunk;
                    let idx: number;
                    while ((idx = buffer.indexOf('\n\n')) !== -1) {
                        const block = buffer.slice(0, idx);
                        buffer = buffer.slice(idx + 2);
                        const dataLine = block.split('\n').find((l) => l.startsWith('data:'));
                        if (!dataLine) continue;
                        try {
                            const payload = JSON.parse(dataLine.slice(5));
                            if (payload._type === 'ui.collection.data') {
                                capture.frames.push({
                                    count: payload.meta?.count ?? -1,
                                    titles: (payload.data ?? []).map((e: { title: string }) => e.title),
                                });
                            }
                        } catch {
                            /* heartbeats / non-JSON frames are irrelevant */
                        }
                    }
                });
                resolve(capture);
            },
        );
        req.on('error', reject);
        req.end();
    });
}

async function api(path: string, body: object, host: string | null): Promise<{ ok: boolean; event?: { id: string } }> {
    const base = baseUrl();
    const raw = JSON.stringify(body);
    return new Promise((resolve, reject) => {
        const req = http.request(
            {
                method: 'POST',
                hostname: base.hostname,
                port: base.port,
                path,
                headers: {
                    'Content-Type': 'application/json',
                    'Content-Length': Buffer.byteLength(raw),
                    ...(host ? { Host: host } : {}),
                },
            },
            (res) => {
                let out = '';
                res.setEncoding('utf8');
                res.on('data', (c: string) => (out += c));
                res.on('end', () => {
                    try {
                        resolve(JSON.parse(out));
                    } catch (e) {
                        reject(new Error(`non-JSON response for ${path}: ${out.slice(0, 200)}`));
                    }
                });
            },
        );
        req.on('error', reject);
        req.end(raw);
    });
}

function saveEvent(title: string, startsAt: string, host: string | null) {
    return api('/platform/calendar/events/save', { title, startsAt, endsAt: startsAt.replace(' 10:', ' 11:') }, host);
}

async function waitFor(predicate: () => boolean, ms: number): Promise<boolean> {
    const deadline = Date.now() + ms;
    while (Date.now() < deadline) {
        if (predicate()) return true;
        await new Promise((r) => setTimeout(r, 250));
    }
    return predicate();
}

test.describe('calendar two-tenant SSE isolation', () => {
    test('streams are tenant-isolated in data AND in live re-runs', async () => {
        test.setTimeout(60_000);
        const run = Date.now().toString(36);
        const t = (name: string) => `e2e-${run} ${name}`;
        const created: Array<{ id: string; host: string | null }> = [];
        const track = (res: { event?: { id: string } }, host: string | null) => {
            if (res.event?.id) created.push({ id: res.event.id, host });
        };

        let defaultStream: SseCapture | null = null;
        let demoStream: SseCapture | null = null;
        try {
            // Seed one event per tenant BEFORE connecting, then open both streams.
            track(await saveEvent(t('default-seed'), '2036-01-05 10:00:00', null), null);
            track(await saveEvent(t('demo-seed'), '2036-01-05 10:00:00', DEMO_HOST), DEMO_HOST);

            defaultStream = await openStream(null);
            demoStream = await openStream(DEMO_HOST);

            // 1. Initial paint: each stream sees its own seed and NOT the other's.
            expect(await waitFor(() => defaultStream!.frames.length >= 1, 8_000)).toBe(true);
            expect(await waitFor(() => demoStream!.frames.length >= 1, 8_000)).toBe(true);
            expect(defaultStream.frames[0].titles).toContain(t('default-seed'));
            expect(demoStream.frames[0].titles).toContain(t('demo-seed'));

            // 2+3. Write in DEMO: the demo stream re-runs AS DEMO...
            track(await saveEvent(t('demo-live'), '2036-01-06 10:00:00', DEMO_HOST), DEMO_HOST);
            expect(
                await waitFor(() => demoStream!.frames.some((f) => f.titles.includes(t('demo-live'))), 10_000),
            ).toBe(true);

            // ...and write in DEFAULT: the default stream re-runs AS DEFAULT.
            track(await saveEvent(t('default-live'), '2036-01-06 10:00:00', null), null);
            expect(
                await waitFor(() => defaultStream!.frames.some((f) => f.titles.includes(t('default-live'))), 10_000),
            ).toBe(true);

            // 4. No frame on either stream ever carried the other tenant's rows —
            //    this is the assertion that caught the real foreign-context re-run bug.
            const demoTitles = [t('demo-seed'), t('demo-live')];
            const defaultTitles = [t('default-seed'), t('default-live')];
            for (const frame of defaultStream.frames) {
                for (const foreign of demoTitles) expect(frame.titles).not.toContain(foreign);
            }
            for (const frame of demoStream.frames) {
                for (const foreign of defaultTitles) expect(frame.titles).not.toContain(foreign);
            }
        } finally {
            defaultStream?.close();
            demoStream?.close();
            // Tenant-scoped delete: each row must be deleted AS its owner.
            for (const row of created) {
                await api('/platform/calendar/events/delete', { id: row.id }, row.host).catch(() => undefined);
            }
        }
    });
});
