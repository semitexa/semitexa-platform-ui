# ADR-0001 — Semitexa UI Transport Unification

**Status**: Proposed. No runtime behavior changed in this slice — see §13.
**Owners**: framework (semitexa-ssr) + platform-ui (semitexa-platform-ui).
**Companion docs**: `packages/semitexa-platform-ui/docs/primitives.md` (component-side details), `vendor/semitexa/ssr/src/Application/Handler/PayloadHandler/UiEventEndpointHandler.php` (foundation handler docblock — "Step-1 scope … later steps").
**Supersedes (eventually)**: every reference to `POST /__ui/dispatch` and `GET /__ui/stream` as *primary* transport in `primitives.md`. Both endpoints stay during migration but become temporary compatibility layers, never the long-term target.

> Location note: this ADR lives in `packages/semitexa-platform-ui/docs/` because that's where the duplicate transports were introduced and where the platform.grid runtime + dispatcher overlay live. The framework changes the ADR proposes (handler resolution on `/__ui/event`, typed messages on `/__semitexa_kiss`) will need a mirror entry in `semitexa-ssr` once the framework slice begins.

---

## 1. Current problem

The Semitexa UI runtime is currently expressed through **two parallel transports**:

| Direction | Framework-canonical (semitexa-ssr) | Platform-ui overlay (semitexa-platform-ui) |
| --- | --- | --- |
| Inbound UI event | `POST /__ui/event` — **stub** (`UiEventEndpointHandler` returns `not_implemented`). Validates envelope + verifies signed ctx; refuses to dispatch. | `POST /__ui/dispatch` — fully implemented `UiInteractionDispatcher` (signed-ctx + replay + authorizer + validator + CSRF + handler resolve + patch publish). |
| Outbound UI patch / stream | `GET /__semitexa_kiss` — `AsyncResourceSseServer`, session-keyed, used today for deferred SSR fragment streaming. | `GET /__ui/stream` — `UiSseStreamHandler` + `UiSsePatchQueue` (Redis LIST / in-memory), HMAC channel-token authorization. |

Both pairs are real, both compile, both ship. As of today **the framework canonical inbound is a stub**, so:

- Form submits go through `/__ui/dispatch` (platform-ui).
- Grid SSE refresh markers come from `/__ui/stream` (platform-ui).
- Grid pagination / filter / sort / page-size changes are **not** UI events at all — `grid-runtime.js:reload()` does a plain `fetch('/ui-playground/admin/leads/grid-data?…')`, bypassing the dispatcher entirely. The SSE channel is used purely as a "something changed" ping that triggers another REST fetch.

The architectural intent ("UI event → canonical endpoint → handler → canonical stream → frontend") is currently satisfied **only by forms** (and only through the platform-ui overlay, not the framework canonical endpoint).

## 2. Existing endpoints — full role table

Verified by `bin/semitexa routes:list` and source inspection. Frontend usage is verified by grepping the asset bodies.

| Endpoint | Package | Class | Role today | Frontend caller today |
| --- | --- | --- | --- | --- |
| `POST /__ui/event` | semitexa-ssr | `UiEventEnvelopePayload` → `UiEventEndpointHandler` | **STUB**. Validates `UiEventEnvelope::fromArray()` shape + verifies signed ctx; returns 202 with `{status:'accepted', phase:'foundation', resolution:{status:'not_implemented', reason:'handler_resolution_pending', plan:'framework-layer-improvements.md §7.6 + §15 step 4.5'}}`. Never dispatches. | None. |
| `POST /__ui/dispatch` | semitexa-platform-ui | `UiDispatchPayload` → `UiDispatchHandler` → `UiInteractionDispatcher` | Fully implemented inbound dispatcher. Verifies signed ctx via `SignedContext::verify` (framework), guards `payload` keys against `dispatchId`/`requestId`/`eventId` smuggling, claims replay key (`sha256(ctx):dispatchId`) via `UiReplayStoreInterface`, runs `UiInteractionAuthorizerInterface`, resolves handler, validates response patches via `UiPatchValidator`, returns `{patches:[…]}`. | `event-runtime.js:attachTransport({endpoint:'/__ui/dispatch'})`. Wired by `platform.form` (and any other component that captures DOM events through the manifest system). |
| `GET /__ui/stream` | semitexa-platform-ui | `UiSseStreamPayload` → `UiSseStreamHandler` | SSE patch channel. HMAC channel token (`UiSseChannelToken` — purpose claim `c='ui-patch-stream'`, channel id `ch`, exp TTL 600s by default). Per-channel FIFO `UiSsePatchQueue` (Redis LIST / in-memory). Connection lease + per-IP/global cap via `UiSseConnectionLimiterInterface`. Wire shape: `event: ui.patch` SSE frames carrying a JSON `{patches:[…UiResponsePatch], publishedAt}` envelope. | `event-runtime.js:attachSse({url})` → `EventSource`. Used by `platform.grid` only for the refresh-marker (lead grid). |
| `GET /__semitexa_kiss` | semitexa-ssr | `SseKissPayload` → `SseKissHandler` → `AsyncResourceSseServer` | Framework SSE for deferred SSR fragments. Built on Swoole Tables (`session_id → worker_id`, deliver queue). `AsyncResourceSseServer::deliver(sessionId, data)` takes arbitrary JSON — the "HTML fragment" wire shape is a *convention* of the deferred-block listeners, not a hardcoded protocol. | `semitexa-twig.js` for deferred SSR slot streaming. |
| `GET /sse` | semitexa-ssr | `SseEndpointPayload` → `SseEndpointHandler` | Older session-keyed SSE endpoint (transport: SSE). | None today. |
| `POST /__semitexa_component_event` | semitexa-ssr | `ComponentEventDispatchPayload` → `ComponentEventDispatchHandler` → `ComponentEventBridge` + `ComponentRegistry` | Older same-origin component-event bridge. Pre-dates `/__ui/event`. | None today. |

## 3. Why the current split is wrong

1. **Two inbound endpoints with overlapping intent.** Both `/__ui/event` and `/__ui/dispatch` exist to receive a UI event from the frontend; both verify the same framework-issued signed ctx (`SignedContext` is shared). One is a stub, the other is the working implementation. New components don't know which to target. Forms target the working one. Grid targets neither (it uses REST instead).
2. **Two outbound SSE servers.** `AsyncResourceSseServer` and `UiSseStreamHandler` are independent: separate auth model (session vs. HMAC channel-token), separate backing storage (Swoole Tables + framework deliver queue vs. Redis LIST `UiSsePatchQueue`), separate connection limiters, separate poll loops, separate wire shapes. The env knobs (`SSE_MAX_CONN_PER_IP`, `SSE_MAX_CONN_GLOBAL`) are shared by accident, not by design — `UiSseStreamHandler` re-reads them itself.
3. **Components are free to invent transport.** `platform.grid` did exactly that: it skipped the dispatcher and the patch stream entirely and went straight to `fetch(/grid-data?…)`. Every future component that follows the same pattern dilutes the architecture further. The framework has no enforcement story today.
4. **No channel binding between inbound and outbound.** When `/__ui/dispatch` runs a handler, the resulting `{patches}` are returned in the HTTP response synchronously. There is no contract by which a handler can publish a patch back through `/__ui/stream` to *this caller's* subscriber channel — the channel token is minted in a different request (the page handler) and isn't visible to the dispatcher. `UiPlaygroundLeadGridRefreshPublisher` works around this by maintaining its own topic registry (`UiPlaygroundLeadGridRefreshTopicInterface`) and fanning out to all subscribers; that's a workaround, not the architecture.
5. **Grid envelope can't travel as a patch.** Existing patch ops are `setText`, `setValue`, `setAttribute` — leaf-DOM mutations. A whole `UiGridDataResponse` (`{rows, pagination, filters}`) doesn't fit. Even if the inbound side were unified, the outbound side has no message type for "here's a coherent state update for this component instance."

## 4. Final target architecture

```
                  ┌─────────────────────┐         ┌────────────────────────┐
   browser ─POST→ │  /__ui/event        │ ─────→  │ UiResponseDispatcher   │ ─→ handler
                  │  (semitexa-ssr,     │         │ (extracted from        │       │
                  │   single inbound)   │         │  UiInteractionDispatcher) │    │
                  └─────────────────────┘         └────────────────────────┘       │
                                                                                   ↓
                  ┌─────────────────────┐         ┌────────────────────────┐    publish typed message
   browser ←SSE── │  /__semitexa_kiss   │ ←────── │ canonical patch publisher
                  │  (semitexa-ssr,     │         │ (backed by AsyncResourceSseServer)
                  │   single outbound,  │         └────────────────────────┘
                  │   typed messages)   │
                  └─────────────────────┘
```

### 4.1 Canonical inbound: `POST /__ui/event`

The framework-canonical endpoint becomes the single inbound. `UiEventEnvelopePayload` is unchanged on the wire. `UiEventEndpointHandler` graduates from "stub" to "real dispatcher": after envelope validation + signed-ctx verify, it calls a `UiResponseDispatcherInterface` whose implementation is the existing `UiInteractionDispatcher` logic *extracted* from platform-ui and *moved or reachable from* the framework. The dispatcher contract is:

```
dispatch(ctx: string, dispatchId: string, payload: array, subscriberChannelId: ?string): UiInteractionResult
```

The new `subscriberChannelId` parameter is the channel the dispatcher should publish back to (see §10).

### 4.2 Canonical outbound: `/__semitexa_kiss`

The single SSE stream. Wire format gains a typed-message envelope (today it's `event: data` carrying a JSON fragment; deferred-block messages are one convention on top, UI patches become another):

```
event: <type>      // ssr.fragment | ui.patch | ui.componentState | ui.error | heartbeat
data: <JSON>
```

`AsyncResourceSseServer::deliver(sessionId, data)` already takes arbitrary JSON — only the SSE *event name* needs to be derivable from `data` (e.g. `data._type`). Deferred-block listeners stay emitting `ssr.fragment` messages. UI dispatcher writes `ui.patch` and `ui.componentState` messages.

### 4.3 Required message types

| `event:` | `data` schema | Producer | Consumer | Use |
| --- | --- | --- | --- | --- |
| `ssr.fragment` | `{slot, html, done}` (already shipping) | deferred-block listeners | `semitexa-twig.js` | Existing SSR fragment streaming. Unchanged. |
| `ui.patch` | `{instance, patches:[{op, target, value}], correlationId?}` | `UiResponseDispatcher` (post-handler) | `event-runtime.js` (safe applier — `setText`/`setValue`/`setAttribute` only) | Leaf-DOM mutations. Existing patch shape on the platform-ui side, lifted onto the canonical stream. |
| `ui.componentState` | `{instance, componentName, state, correlationId?}` | `UiResponseDispatcher` (post-handler) | per-component runtime (e.g. `grid-runtime.js`) | Whole-component state updates. **New message type.** Grid envelopes (`{rows, pagination, filters}`), form result rosters, etc. The component runtime validates `state` against a per-component schema before any DOM write. |
| `ui.error` | `{correlationId, reason, message}` | dispatcher (handler threw, authorizer denied, validation failed) | runtime (renders the safe error banner) | Out-of-band error envelope so frontend can clear its `pending` flag for a specific event without parsing patches. |
| `heartbeat` | `{at}` | server heartbeat loop | runtime keep-alive | Already supported by `AsyncResourceSseServer`. |

### 4.4 No new SSE server

`/__ui/stream` becomes a thin adapter over `/__semitexa_kiss` during migration (publishes into `AsyncResourceSseServer::deliver` instead of `UiSsePatchQueue`) and is then removed. No third stream is introduced.

## 5. Inbound decision — `/__ui/event` becomes canonical

| | Today | Target |
| --- | --- | --- |
| `POST /__ui/event` (ssr) | stub | **canonical inbound**, dispatches via `UiResponseDispatcher` |
| `POST /__ui/dispatch` (platform-ui) | working dispatcher | **temporary compatibility shim** — delegates to the same `UiResponseDispatcher` (no behavioral drift), then deprecated and removed once `event-runtime.js` is repointed |

The frontend default endpoint in `event-runtime.js` (currently `'/__ui/dispatch'`) is **the only frontend change required** to flip — and it's gated behind `transport.attach({endpoint})`, so callers can pin either value during the migration window.

## 6. Outbound decision — `/__semitexa_kiss` becomes canonical

| | Today | Target |
| --- | --- | --- |
| `/__semitexa_kiss` (ssr) | SSR fragments only | **canonical outbound**, typed `event: <type>` messages, also carries `ui.patch` / `ui.componentState` / `ui.error` |
| `/__ui/stream` (platform-ui) | independent SSE server with own queue / auth / limiter | **temporary compatibility shim** — its handler reads from the canonical stream's per-subscriber buffer (or is replaced by a redirect to the canonical URL with the right token claims) during migration, then removed |
| `/sse` (ssr) | unused today | retained as a generic alias for the canonical stream OR removed; out of scope for this ADR |
| `/__semitexa_component_event` (ssr) | unused today, pre-dates `/__ui/event` | deprecated as already-superseded; out of scope for this ADR's first pass |

## 7. Channel / session binding

The hardest unsolved problem today. Two viable models:

### 7.1 Model A — channel-id in signed ctx (RECOMMENDED)

When a page renders, the page handler mints a single canonical `subscriberChannelId` and:

- includes it inside the **same signed ctx blob** that every component on the page already carries (`SignedContext::sign(claims + {subscriberChannelId})`);
- opens the canonical SSE stream against `/__semitexa_kiss?channel=<channelId>&token=<channelId-signed-blob>` (or equivalent — name TBD by the framework slice);
- so when any UI event arrives at `/__ui/event`, the dispatcher reads `subscriberChannelId` from the verified ctx and publishes responses via `AsyncResourceSseServer::deliver(channelId, {…})`.

This re-uses the existing trust boundary (signed ctx) and needs zero new auth machinery.

### 7.2 Model B — session-based binding (REJECTED for this ADR)

Bind the subscriber channel to the framework `sessionId` directly (the way `AsyncResourceSseServer` already does internally). Simpler in some ways, but:

- Multi-tab in the same session would share a channel — bad UX for grids on different tabs.
- Cross-session correlation (anonymous browsing) is fragile.
- The signed-ctx model already exists as a trust boundary and is not session-bound, so Model A composes more cleanly.

We adopt **Model A**.

## 8. Required patch / state shapes

| Schema | Today | Target |
| --- | --- | --- |
| `UiResponsePatch` (`{op, target, value}`, allowed ops: `setText`, `setValue`, `setAttribute`) | platform-ui owns it | move to ssr (`Semitexa\Ssr\Application\Service\UiEvent\UiResponsePatch`); platform-ui keeps an alias during migration |
| `UiComponentState` (`{componentName, state, schemaVersion}`) | does not exist | new — defined in ssr; per-component `state` shape registered by the component package (e.g. `platform.grid` registers `UiGridDataResponse` as its state schema) |
| `UiEventEnvelope` | shipped in ssr | unchanged |
| `UiInteractionResult` | platform-ui owns it (`{patches:[…], debug?}`) | move to ssr or generalize: dispatcher returns either a `patches` list (DOM mutations) or a `componentState` (state replacement), not both. Existing form path already produces only patches; this stays valid. |

## 9. Security invariants — none are weakened

The unification deliberately *preserves* every existing check:

- Signed-ctx HMAC verify (`SignedContext::verify`) is already shared by both endpoints today — unchanged.
- `dispatchId` smuggling guard (payload key allow-list) — unchanged, moves with the dispatcher.
- Replay claim (`sha256(ctx):dispatchId` in `UiReplayStoreInterface`) — unchanged.
- `UiInteractionAuthorizerInterface` — unchanged.
- CSRF one-time token store (`UiFormSubmitCsrfTokenStore`) — unchanged.
- Per-grid cursor fingerprint guard (`UiPlaygroundLeadSubmissionCursor::SAFE_ID_PATTERN` + `filterFingerprint`) — unchanged; grid criteria still travel through the existing cursor/criteria classes after migration.
- Patch validator (`UiPatchValidator`) — unchanged. `UiComponentState` will get a separate per-component validator registered by each component package.
- SSE connection limits — re-use `AsyncResourceSseServer` limits; the parallel `UiSseConnectionLimiterInterface` is removed at the end of migration.

No transport unification step removes a check.

## 10. Migration phases

Each phase is one or more **dedicated slices**. None of them are part of this ADR — this ADR only fixes the direction.

**Phase 0** (this ADR — no code changes). Document the decision. Identify gaps.

**Phase 1** (ssr-framework slice). Implement `UiResponseDispatcherInterface` + a working `UiEventEndpointHandler` that calls it. The default implementation is the existing platform-ui `UiInteractionDispatcher` logic, lifted into ssr (or kept in platform-ui and bound via `#[SatisfiesServiceContract]` until ssr is ready). All existing `/__ui/dispatch` tests run against the new dispatcher path and stay green. **/__ui/dispatch keeps its current handler; it's the same dispatcher under the hood.**

**Phase 2** (ssr-framework slice). Teach `AsyncResourceSseServer::deliver()` to emit typed `event:` lines based on a `_type` key in the data envelope. Define `ui.patch` / `ui.componentState` / `ui.error` message schemas in `Semitexa\Ssr\Application\Service\UiEvent\`. Bind the canonical patch publisher (which writes through `AsyncResourceSseServer`) under a `#[SatisfiesServiceContract]`. **Existing deferred-block streaming is unchanged.**

**Phase 3** (platform-ui slice). Repoint `event-runtime.js`'s default `transport.attach()` endpoint from `/__ui/dispatch` → `/__ui/event`. Repoint `attachSse()`'s URL builder to mint a `/__semitexa_kiss` URL with a channel-id-bearing signed ctx. **Both legacy endpoints keep responding to direct callers but stop being the runtime default.**

**Phase 4** (platform-ui slice — grid migration). Register `grid.criteria.changed` semantic event. Emit signed ctx + capture manifest from `grid.html.twig`. Server handler is `LeadAdminGridDataHandler` *unchanged* — it's invoked via the dispatcher and its `UiGridDataResponse` is published as a `ui.componentState` message tied to the grid's instance id. `grid-runtime.js` removes the direct fetch; subscribes to `ui.componentState` for its instance and applies state. `GET /grid-data` is retained as no-JS fallback / diagnostic / test seam.

**Phase 5** (platform-ui slice — deprecations). Delete `UiSseStreamHandler`/`UiSsePatchQueue`/`UiSseConnectionLimiterInterface`/`UiSseChannelToken` and the `/__ui/stream` route. Delete `UiDispatchHandler` + `UiDispatchPayload` + the `/__ui/dispatch` route. Delete `event-runtime.js`'s legacy endpoint default.

## 11. Explicit non-goals

- Not building a third SSE stream.
- Not building a third inbound endpoint.
- Not introducing a per-component event bus.
- Not introducing a generic "GridDataProvider" framework (out of scope for this ADR — `UiGridDataResponse` stays as a contract per consumer, validated by a per-component schema).
- Not adding total-count / offset / jump-to-page to the cursor model.
- Not changing CSRF, authorizer, replay, signed-ctx, or cursor-fingerprint trust boundaries.
- Not migrating SSR deferred-block streaming away from its existing fragment wire shape.
- Not introducing a new IDE, build step, or transport library. The wire format remains plain SSE + JSON.

## 12. Migration strategy per component

### 12.1 Grid migration

After Phase 2: each grid renders with a signed ctx, captures `grid.page.next` / `grid.page.previous` / `grid.page.gotoKnown` / `grid.pageSize.change` / `grid.sort.change` / `grid.search.submit` / `grid.filter.change` semantic events. Each event POSTs to `/__ui/event`. The dispatcher resolves to the existing grid-data handler. The handler produces a `UiGridDataResponse`. The dispatcher publishes it as `ui.componentState{componentName:'platform.grid', instance, state:UiGridDataResponse}` over `/__semitexa_kiss`. `grid-runtime.js` subscribes to its instance's component-state messages and replaces the table tbody / pagination footer / filter strip.

### 12.2 Form migration

After Phase 3: `event-runtime.js`'s default endpoint flips to `/__ui/event`. Forms keep their existing semantic event names + ctx + dispatch ids. The dispatcher's behavior is identical (it's the same code path under the hood). Form-driven response patches now travel over `/__semitexa_kiss` as `ui.patch` instead of inline in the dispatch response — `event-runtime.js`'s applier already keeps `setText`/`setValue`/`setAttribute` allow-listing; only the consumer side moves from "JSON response body" to "SSE event payload" with a correlation id matching the `dispatchId`.

## 13. Deprecation plan

| Asset | After Phase 1 | After Phase 3 | After Phase 5 |
| --- | --- | --- | --- |
| `POST /__ui/event` handler | working, calls the canonical dispatcher | unchanged | canonical |
| `POST /__ui/dispatch` | working, also calls the canonical dispatcher (same code) | still responds for direct callers | route removed; class deleted |
| `GET /__ui/stream` | unchanged | thin adapter over the canonical kiss stream OR redirect | route removed; queue/limiter/token deleted |
| `event-runtime.js:attachTransport` default | `/__ui/dispatch` | `/__ui/event` | `/__ui/event` (legacy default deleted) |
| `event-runtime.js:attachSse` URL | platform-ui caller-provided `/__ui/stream` URL | canonical `/__semitexa_kiss?…channel=…` | only `/__semitexa_kiss` |
| `grid-runtime.js:reload` direct fetch | unchanged | unchanged | runs `_only_` for no-JS fallback / explicit re-hydrate after reconnect |
| `UiInteractionDispatcher` class | moved or aliased into ssr; platform-ui keeps a class-alias for back-compat | deprecated alias | removed |
| `UiSsePatchPublisher` / `UiSsePatchQueue` | wrapped by canonical publisher | adapter only | removed |

## 14. Risks and mitigations

| Risk | Mitigation |
| --- | --- |
| Phase 1 widens the framework canonical endpoint while platform-ui still hosts the working dispatcher. Drift between `/__ui/dispatch` and `/__ui/event` behaviour. | Phase 1's deliverable is that **both endpoints call the same dispatcher**. Test asymmetry surfaces immediately. |
| Phase 2 changes `AsyncResourceSseServer` wire to emit typed `event:` lines; deferred-block consumers break. | Make typed emission opt-in: default event name stays `data` for callers that don't set `_type`. Existing deferred-block code path stays untyped until it migrates. |
| Channel id smuggling — a hostile signed ctx claims a different user's channel. | Channel id lives inside the same signed ctx the page mints. A signed ctx that names a channel not minted by the same renderer cannot be forged without the HMAC secret. Add a per-channel `iss` claim in the framework slice if defence-in-depth needs strengthening. |
| Removing `/__ui/stream` breaks downstream consumers depending on its exact wire format. | Phase 5 is the last phase; Phase 3 and 4 give a long window where both stream URLs coexist. Public docs (`primitives.md`) explicitly mark `/__ui/stream` as deprecated during that window. |
| Phase 4 grid migration breaks the lead grid + demo-submissions grid simultaneously. | Both grids share `platform.grid`; the migration is one slice. The existing handler-level tests are reusable as-is (the handler doesn't move). |

## 15. Code-level gap analysis

For each required capability, current ownership and the gap to close. Status legend: ✅ ready, 🟡 partially ready, 🔴 missing.

| # | Capability | Current class / file | Status | Gap | Proposed target / interface | Package owner | Independently shippable? |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | Verify UI event signed ctx | `Semitexa\Ssr\Application\Service\UiEvent\SignedContext::verify` | ✅ | None — already shared by both endpoints today. | — | semitexa-ssr | Yes (no change). |
| 2 | Resolve semantic UI event handler | `Semitexa\PlatformUi\Application\Service\Event\UiInteractionDispatcher::dispatch` | 🟡 | Logic is in platform-ui; needs extraction into a framework-callable `UiResponseDispatcherInterface` so `UiEventEndpointHandler` can call it without depending on platform-ui. | New interface `Semitexa\Ssr\Application\Service\UiEvent\UiResponseDispatcherInterface`; existing class becomes the default implementation. | semitexa-ssr (interface) + semitexa-platform-ui (impl, eventually re-homed) | Yes — interface + binding can land before platform-ui re-homes. |
| 3 | Authorize UI event | `Semitexa\PlatformUi\Application\Service\Event\UiInteractionAuthorizerInterface` | 🟡 | Lives in platform-ui; framework dispatcher needs a binding. The interface itself is sound. | Keep interface; framework binds the same default (`AllowAllUiInteractionAuthorizer`) until project overrides. | semitexa-platform-ui (interface), semitexa-ssr (consumer) | Yes. |
| 4 | Replay / idempotency | `Semitexa\PlatformUi\Application\Service\Event\UiReplayStoreInterface` + `CacheBackedUiReplayStore` | 🟡 | Same: lives in platform-ui; framework needs to inject it. | Keep interface; relocate optional. | platform-ui (interface), ssr (consumer) | Yes. |
| 5 | Validate event payload (smuggling-key guard) | `Semitexa\PlatformUi\Application\Service\Event\UiPayloadFieldGuard` | 🟡 | Static helper; needs to move alongside the dispatcher OR stay as a shared util. | Move to `Semitexa\Ssr\Application\Service\UiEvent\UiPayloadFieldGuard`. | semitexa-ssr | Yes. |
| 6 | Produce component patch response | `Semitexa\PlatformUi\Domain\Model\Event\UiResponsePatch` + `UiPatchValidator` | 🟡 | Move to ssr or alias from there. | `Semitexa\Ssr\Application\Service\UiEvent\UiResponsePatch` + `UiPatchValidator`. | semitexa-ssr | Yes. |
| 7 | Produce component STATE response | — | 🔴 | Does not exist. Needed for grid + future composites. | New `Semitexa\Ssr\Application\Service\UiEvent\UiComponentStateMessage` carrying `{componentName, instance, state, schemaVersion}`. Per-component schema registered by the owning package (e.g. `platform.grid` registers `UiGridDataResponse`). | semitexa-ssr (envelope) + each component package (its schema) | Yes for envelope; per-component schema work belongs to component migration slices. |
| 8 | Publish response to canonical stream | `Semitexa\PlatformUi\Application\Service\Event\UiSsePatchPublisher` (writes into `UiSsePatchQueue`) + `Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer::deliver` (writes into Swoole deliver table) | 🟡 | Two publish paths today. Canonical publisher needs to wrap `AsyncResourceSseServer::deliver` so the SSE event name is set from the message `_type`. | New `Semitexa\Ssr\Application\Service\UiEvent\CanonicalPatchPublisher` (or rename); `UiSsePatchPublisher` becomes a thin alias during migration. | semitexa-ssr | Yes once Phase 2 lands. |
| 9 | Bind frontend subscriber channel ↔ backend dispatch | — | 🔴 | No mechanism today. Page handler mints `UiSseChannelToken` (platform-ui), but the dispatcher pipeline cannot read it. | Page handler includes `subscriberChannelId` claim inside the same signed ctx every component on the page already mints. Dispatcher reads claim during ctx verify, passes to the canonical publisher. | semitexa-ssr (signed-ctx claim convention) + page handlers (mint behaviour) | Yes — additive change to the signed-ctx claim set; old ctx without the claim simply skips post-handler publish. |
| 10 | Frontend sends UI event through one endpoint | `event-runtime.js:attachTransport({endpoint:'/__ui/dispatch'})` | 🟡 | Endpoint is configurable; default needs to flip once `/__ui/event` is real. | One-line default change after Phase 1 ships. | semitexa-platform-ui | Yes after Phase 1. |
| 11 | Frontend receives typed UI messages through one stream | `event-runtime.js:attachSse({url})` + per-event handlers on `document` | 🟡 | URL is caller-provided; needs canonical default and listeners for new typed events. | `attachSse({url:'/__semitexa_kiss?…'})` + new listener entries for `ui.patch` / `ui.componentState` / `ui.error`. | semitexa-platform-ui | Yes after Phase 2. |
| 12 | Grid can request data through semantic event | — | 🔴 | Grid template emits no ctx, no manifest; runtime does `fetch(/grid-data)`. | New manifest entries for grid controls; new semantic event names; grid handler called via dispatcher. | semitexa-platform-ui | Phase 4 slice. |
| 13 | Form submit can use the same inbound path | `platform.form` template + `event-runtime.js` capture manifest | ✅ today (via `/__ui/dispatch`) | Just repoint the endpoint default once `/__ui/event` is real. | — | semitexa-platform-ui | Phase 3 slice. |
| 14 | Existing direct endpoints remain as fallback only | `LeadAdminGridDataHandler` / `DemoSubmissionsAdminGridDataHandler` | ✅ today | After grid migration, runtime stops hitting them as primary path; tests + no-JS path still do. | — | semitexa-platform-ui + UiPlayground (project) | Phase 4 slice. |

## 16. What this slice DID and did NOT change

This ADR slice **did not change runtime behavior**. It added one document:

- `packages/semitexa-platform-ui/docs/transport-architecture.md` (this file)

It did not add classes, interfaces, services, endpoints, lifecycle listeners, frontend assets, env vars, CSP rules, container bindings, route attributes, or test fixtures. Every endpoint, every dispatcher, every SSE stream, every patch shape, every signed-ctx claim, every connection limiter, every wire format, every component runtime works exactly as it did before this ADR was written.

Reason: per Phase 3 of the brief, **Option D (stop after ADR + gap analysis)** is the right posture today. The four cross-package decisions §10 calls out can only be safely made and tracked once they're written down; introducing even an empty `UiResponseDispatcherInterface` would imply a binding direction (ssr → platform-ui or vice versa) that the operator hasn't yet ratified. Once §10 Phase 1 is approved, the next slice is the framework-side dispatcher contract — and it's a slice owned by `semitexa-ssr`, not by this downstream project.

The path forward this ADR enables — narrowest-possible next slice:

> **Phase 1: in `semitexa-ssr`, replace the `UiEventEndpointHandler`'s `not_implemented` branch with a call to a new `UiResponseDispatcherInterface` whose default binding is the existing `UiInteractionDispatcher` from semitexa-platform-ui.** Behavior change: zero (the existing platform-ui dispatcher already does the work; we just give the framework endpoint a way to call it). Test surface: `UiEventEndpointHandlerTest` flips from "asserts not_implemented" to "asserts dispatch through" against the existing dispatcher's behavior. Tag-able as one framework slice.

## 17. Status updates by phase

**Phase 1** — shipped framework-side in `semitexa/ssr 2026.05.18.1420` (PR #59 fix included): `POST /__ui/event` delegates to `UiResponseDispatcherInterface`, dispatcher `Throwable` is logged via `StaticLoggerBridge`, `encodeEnvelope` failure is safely handled, signed-ctx verification remains pre-dispatch. Framework ships `NotConfiguredUiResponseDispatcher` as the default binding so the endpoint produces a stable `accepted / foundation / dispatcher_not_configured` envelope when no concrete dispatcher is installed.

**Phase 2** — shipped framework-side in `semitexa/ssr 2026.05.18.1642`: `AsyncResourceSseServer` emits typed `event:` lines based on a reserved `_type` field; allow-list is `ssr.fragment` / `ui.patch` / `ui.componentState` / `ui.error` via `UiSseEventType`; new message value objects `UiPatchMessage` / `UiComponentStateMessage` / `UiErrorMessage`; `CanonicalUiMessagePublisherInterface` + default `AsyncResourceSseMessagePublisher` use the existing KISS transport. Unknown `_type` is dropped with a logged warning; payloads without `_type` are byte-identical to the pre-Phase-2 wire shape.

**Phase 3 (back-end adapter only)** — shipped platform-ui-side as of 2026-05-19: `Semitexa\PlatformUi\Application\Service\Event\PlatformUiResponseDispatcher` is bound via `#[SatisfiesServiceContract(of: UiResponseDispatcherInterface::class)]`. `POST /__ui/event` now dispatches through the existing `UiInteractionDispatcher` pipeline. Mapping is mechanical: `signedContext → ctx`, `eventId → dispatchId`, `payload → payload`. The legacy dispatcher's hardened 9-step pipeline (payload-field guard → signed-ctx verify → registry resolution → replay-claim → authorizer → handler invocation → patch validation) runs unchanged.

**Phase 3 (frontend transport repoint)** — shipped platform-ui-side as of 2026-05-19: `event-runtime.js` default endpoint is now `/__ui/event`. The `attachTransport()` wire body branches on the endpoint string:
- `endpoint === '/__ui/event'` → canonical `UiEventEnvelope` body: `{schemaVersion: 1, eventId, correlationId, semanticEvent, signedContext, timestamp, payload}`. `eventId` reuses the existing `ui_evt_<32 hex>` generator (so the adapter's `eventId → dispatchId` map satisfies the legacy strict pattern unchanged); `correlationId` is a fresh `ui_cor_<32 hex>` per attempt; `semanticEvent` is derived as `<component>.<event>` (e.g. `platform.form.submit`); `timestamp` is `new Date().toISOString()` (ISO-8601); `payload` is the existing `payloadObj` shape (`{value}` + optional `form.values`).
- Any other endpoint (including explicit `endpoint: '/__ui/dispatch'`) → unchanged legacy `{ctx, dispatchId, payload}` body, preserving the compatibility contract for direct callers (e.g. the UiPlayground demo pages).

Inline patch handling on the response is **unchanged** — the canonical `UiResponseDispatchResult.body` carries `patches: [UiResponsePatch::toJsonShape, …]` exactly as the legacy `/__ui/dispatch` response did, so `applyResponsePatches(response, captured)` keeps working without modification. Outbound SSE patch delivery via `/__semitexa_kiss` is **NOT** wired in this slice (Phase 4 territory).

**Phase 3 (canonical outbound SSE + auto-attach)** — shipped platform-ui-side as of 2026-05-19:

- **Auto-attach** (gated): `event-runtime.js` now calls `attachTransport()` once at DOMContentLoaded **if and only if** at least one signed platform-ui manifest is parsed AND no caller has already wired a transport AND `fetch` is available AND `window.SEMITEXA_UI_DISABLE_AUTOATTACH !== true`. Non-platform-ui pages emit no manifest → no auto-attach → zero network impact. The capture listener fires only for manifest-declared events, so non-platform forms on the same page are not captured.
- **Canonical typed SSE listeners**: `attachSse({url})` now also subscribes to `ui.componentState` and `ui.error`. The existing `ui.patch` listener shape-detects canonical typed envelopes (`_type: 'ui.patch'`) and routes them through the same safe `applyOnePatch` engine the legacy `/__ui/stream` path uses; legacy `{v, patches}` envelopes continue to work. Same-URL dedupe prevents duplicate EventSource connections.
- **SSE URL**: still **caller-supplied**. `attachSse()` does not auto-open a `/__semitexa_kiss` connection — opening EventSources is per-page page-handler responsibility, since session id must be coordinated with the signed-ctx `sub` claim minted server-side.
- **Subscriber channel binding** (additive): `UiEventManifestBuilder` accepts an optional `subscriberChannelId` parameter. When set, every signed manifest entry carries an additive `sub` claim. Old ctxs minted without it remain valid — the dispatcher falls back to inline patches in that case.
- **Canonical patch publish**: `PlatformUiResponseDispatcher` reads `sub` from verified claims. When present (and the safe shape `[A-Za-z0-9][A-Za-z0-9_-]{0,127}` passes), each `UiResponsePatch` is forwarded as a `UiPatchMessage` via `CanonicalUiMessagePublisherInterface::publish($sub, $msg)`. The HTTP response then drops inline `patches: []` and surfaces `streamedPatchCount: <int>` so clients can correlate. Publisher failures are logged via `StaticLoggerBridge` and the adapter falls back to inline patches — patches are never silently lost.

**Phase 3 (page-handler subscriberChannelId mint + SSE auto-open)** — shipped platform-ui-side as of 2026-05-19:

- **`PlatformUiSseSessionState`**: per-request static holder for the canonical SSE subscriber channel id. `mintIfAbsent()` produces `sse_<32 hex>` on the first call within a request and returns the same id for every subsequent call, so every platform-ui component on the page shares one `sub` claim and one EventSource. `current()` is a no-mint accessor used by `ui_event_manifest()` so the threading is opt-in.
- **`ResetPlatformUiSseSessionListener`**: `#[AsPipelineListener(phase: AuthCheck::class, priority: -1000)]`. AuthCheck is the canonical request-scoped integration point (per `ApplyThemeOnAuthCheckListener` prior art). Lowest priority so the reset runs before any AuthCheck listener can read or write session state — without it, a Swoole worker would leak request A's id into request B and let B publish patches into A's stream.
- **Twig**: two new helpers on `PlatformUiTwigExtension`:
  - `ui_page_sse_session()` — opt-in mint, returns the id as a string (rarely needed directly).
  - `ui_page_sse_session_meta()` — opt-in mint, returns a Markup `<meta name="semitexa-ui-sse-session" content="<id>">` ready to drop into the page.
  - `ui_event_manifest()` reads `PlatformUiSseSessionState::current()` and forwards it as `subscriberChannelId` to the builder. Pages that DO NOT call the helpers produce manifests with no `sub` claim — the dispatcher then keeps returning inline patches, fully backward-compatible.
- **Frontend (`event-runtime.js`)**: `maybeAutoOpenSse()` runs from both readyState branches immediately after `maybeAutoAttachTransport()`. Gates: opt-out flag (`window.SEMITEXA_UI_DISABLE_AUTOATTACH === true`), at least one manifest parsed, `meta[name="semitexa-ui-sse-session"]` present, EventSource available, id matches the safe pattern (`/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/` — same alphabet the dispatcher enforces). On pass it calls `attachSse({url:'/__semitexa_kiss?session_id='+encodeURIComponent(id)})`. Same-URL dedupe is already in `attachSse`, so repeated auto-open calls (HMR, MutationObserver retriggers) never open a second EventSource. The framework's existing `semitexa-twig.js` deferred-SSR stream targets a DIFFERENT URL (`?session_id=…&deferred_request_id=…`) so the two streams coexist without competing.
- **Page opt-in**: `/ui-playground/business/lead-form` calls `{{ ui_page_sse_session_meta() }}` at the top of `block main` — first concrete page wired to the end-to-end canonical path.

**Phase 3 (transport mode policy + drain-on-demand runtime)** — shipped platform-ui-side as of 2026-05-19:

- **`PlatformUiTransportMode`** (enum: `Drain`, `Live`) and **`PlatformUiTransportModePolicy`** (resolver) bake the safety stance into the type system. Drain is the hard public/guest default; live is the explicit opt-in for trusted surfaces (authenticated dashboards, admin tools, monitoring). The string values match `AsyncResourceSseServer::TRANSPORT_MODE_DRAIN` / `…_LIVE` and the `mode=` query parameter on `/__semitexa_kiss`, so the meta tag, the URL, and the server-side resolver share one alphabet.
- **Precedence** (highest first):
  1. Explicit `$mode` argument to `ui_page_sse_session_meta($mode)` — page- or component-level opt-in/-out wins. Unknown values raise `UiTransportModeException` at render time.
  2. Env default `SEMITEXA_UI_TRANSPORT_MODE` — operators flip the deployment-wide default without touching templates. Unknown values raise `UiTransportModeException` at render time (fail-fast, no silent widening to live). Empty / unset falls through to (3).
  3. Hard fallback: `PlatformUiTransportMode::Drain`. A brand-new platform-ui page with no explicit option, on a deployment with no env opt-in, is safe for public/guest traffic.
- **Twig API**: `ui_page_sse_session_meta()` now accepts an optional `?string $mode = null` argument and emits TWO inert meta tags side by side:

  ```html
  <meta name="semitexa-ui-sse-session"    content="sse_…">
  <meta name="semitexa-ui-transport-mode" content="drain|live">
  ```

  Existing call sites with no argument keep working — they get the new drain default. Existing tests for the `sub` claim and session-id sharing remain valid; the helper still mints/returns a single session id per render.
- **Runtime (`event-runtime.js`)**:
  - New `readPageTransportMode()` reads the transport-mode meta and falls back to drain for missing / unknown values (mirrors the server policy's safe default).
  - New shared URL builder `buildKissUrl(sessionId, mode)` so the two call sites cannot drift on the URL shape: `'/__semitexa_kiss?session_id=' + encodeURIComponent(id) + '&mode=' + encodeURIComponent(mode)`.
  - **Live mode** auto-opens the EventSource on DOMContentLoaded — same behaviour as the pre-policy slice, now with explicit `mode=live` in the URL so the server enters its long-lived branch deterministically.
  - **Drain mode** does NOT open an EventSource on DOMContentLoaded. `armDrainOnDemand(sessionId)` registers a `document`-level listener for `semitexa:ui-event:dispatched`; only when the dispatcher reports `response.streamedPatchCount > 0` does the runtime attach to `/__semitexa_kiss?session_id=<id>&mode=drain`. SSR's drain implementation flushes the queue, emits `event: close`, and the close-event handler now calls `source.close()` + removes the connection-table entry so the EventSource terminates deterministically (without it, the browser would treat the server-side close as a transient error and reconnect, defeating drain mode).
  - **Inline fallback unchanged**: a canonical response that carries inline `patches: [...]` (`streamedPatchCount` absent or zero) is still applied through the existing safe applier; no SSE attachment happens.
  - **Same-URL dedupe in `attachSse` unchanged**: repeated auto-open/HMR triggers never open a second EventSource.
  - `/__ui/dispatch` direct callers still receive the legacy wire body; the canonical `/__ui/event` flow is unchanged.

**Phase 3 (still pending)**:
- KISS `/__semitexa_kiss` auth gate: bare requests without `deferred_request_id` are auth-required unless the request supplies a safe-shaped anonymous bearer (`sse_<32 hex>`) under drain mode. The dispatcher's inline-fallback path keeps these pages functional today and remains the safety net while drain admit is rolled out.
- `/__ui/dispatch` route and `UiDispatchHandler` remain — direct callers (e.g. UiPlayground demo pages) still hit it with legacy body.
- `/__ui/stream` route, `UiSseStreamHandler`, `UiSsePatchQueue`, and the channel-token machinery remain.
- `platform.grid` runtime is unchanged: `grid-runtime.js` still does direct `fetch(/grid-data?…)`. Grid migration is Phase 4.

The next slice for ADR Phase 3 final cleanup is removing the `/__ui/dispatch` compatibility shim once every demo page repoints, and starting the Phase 4 grid migration on top of the now-end-to-end canonical channel.

---

*End of ADR-0001.*
