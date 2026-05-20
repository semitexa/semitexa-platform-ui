<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Asset-level invariants for the frontend event runtime.
 *
 * The runtime itself is JavaScript and runs in the browser; PHP can only
 * verify (a) the file exists at the documented location, (b) the asset
 * manifest declares it with the expected scope/position/key, and (c) the
 * JS body satisfies a few hard structural rules we depend on: it must
 * register window.SemitexaUi, it must not contain any network-transport
 * calls, and it must not contain a UiInteractionDispatcher or signature
 * verification call. End-to-end behavior is exercised manually in the
 * playground (and by a future E2E test slice).
 */
final class EventRuntimeAssetTest extends TestCase
{
    private const PACKAGE_ROOT = __DIR__ . '/../../..';
    private const JS_RELATIVE = '/src/Application/Static/js/event-runtime.js';
    private const MANIFEST_RELATIVE = '/src/Application/Static/assets.json';

    private function jsPath(): string
    {
        return self::PACKAGE_ROOT . self::JS_RELATIVE;
    }

    private function js(): string
    {
        $contents = @file_get_contents($this->jsPath());
        if ($contents === false) {
            $this->fail('event-runtime.js missing at ' . $this->jsPath());
        }
        return $contents;
    }

    /**
     * Returns the JS with comments stripped, so structural assertions
     * are not tripped by docstring text that *describes* what the
     * runtime intentionally does NOT contain.
     */
    private function jsCode(): string
    {
        $src = $this->js();
        // Strip /* ... */ blocks (including JSDoc /** ... */).
        $stripped = preg_replace('!/\*.*?\*/!s', '', $src) ?? $src;
        // Strip // line comments (best-effort; tolerates URLs in strings
        // because the runtime never embeds a URL).
        $stripped = preg_replace('!(^|\s)//[^\n]*!', '$1', $stripped) ?? $stripped;
        return $stripped;
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $path = self::PACKAGE_ROOT . self::MANIFEST_RELATIVE;
        $raw = @file_get_contents($path);
        $this->assertNotFalse($raw, 'assets.json missing at ' . $path);
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);
        return $data;
    }

    #[Test]
    public function runtime_file_exists(): void
    {
        self::assertFileExists($this->jsPath());
        self::assertGreaterThan(0, filesize($this->jsPath()));
    }

    #[Test]
    public function runtime_registers_window_semitexaui_iife(): void
    {
        $js = $this->js();
        self::assertStringContainsString("(function () {", $js);
        self::assertStringContainsString("'use strict';", $js);
        self::assertStringContainsString('window.SemitexaUi', $js);
        self::assertStringContainsString('scan:', $js);
        self::assertStringContainsString('manifests:', $js);
        self::assertStringContainsString('onCapture:', $js);
        self::assertStringContainsString('version:', $js);
    }

    #[Test]
    public function runtime_scans_signed_manifest_script_tag(): void
    {
        $js = $this->js();
        self::assertStringContainsString(
            'script[type="application/json"][data-ui-event-manifest]',
            $js,
        );
        self::assertStringContainsString('data-ui-component-instance-id', $js);
    }

    #[Test]
    public function runtime_does_not_auto_dispatch_at_module_init(): void
    {
        $code = $this->jsCode();
        // No XMLHttpRequest / sendBeacon / WebSocket anywhere — neither
        // transport opens these.
        self::assertStringNotContainsString('XMLHttpRequest', $code);
        self::assertStringNotContainsString('navigator.sendBeacon', $code);
        self::assertStringNotContainsString('WebSocket', $code);

        // EventSource is allowed but ONLY inside the sse.attach helper.
        // The exact callsite count is pinned in
        // sse_attach_is_opt_in_and_not_called_at_module_init.

        // The module-init code (everything after `window.SemitexaUi = {` and
        // before the closing `})();`) MUST NOT call fetch directly, must
        // NOT construct an EventSource, must NOT touch the SSE attach
        // helper. Only the closures behind window.SemitexaUi.* do those
        // things, and only after a caller explicitly opts in.
        $initSection = self::tail($code, 'window.SemitexaUi = {');
        self::assertNotSame('', $initSection, 'window.SemitexaUi block must exist');
        self::assertStringNotContainsString('fetch(', $initSection);
        self::assertStringNotContainsString('new EventSource(', $initSection);
    }

    #[Test]
    public function runtime_exposes_opt_in_transport_attach(): void
    {
        $code = $this->jsCode();
        // The transport namespace must exist on window.SemitexaUi.
        self::assertMatchesRegularExpression('/transport:\s*\{/', $code);
        self::assertMatchesRegularExpression('/attach:\s*attachTransport/', $code);
        // attachTransport must use fetch — that's the bridge.
        self::assertStringContainsString('fetch(', $code);
        // …and only inside attachTransport. (Sanity: the only `fetch(` in
        // the file lives in the attachTransport closure body.)
        self::assertSame(1, substr_count($code, 'fetch('));
    }

    #[Test]
    public function transport_default_endpoint_is_canonical_ui_event(): void
    {
        $code = $this->jsCode();
        // Phase 3 Part 2: the runtime default endpoint is now the
        // canonical `POST /__ui/event` route on semitexa-ssr. Callers
        // that explicitly pass `attachTransport({ endpoint:
        // '/__ui/dispatch' })` still get the legacy compatibility
        // endpoint with its legacy wire body.
        self::assertMatchesRegularExpression(
            "/var\\s+DEFAULT_TRANSPORT_ENDPOINT\\s*=\\s*['\"]\\/__ui\\/event['\"]\\s*;/",
            $code,
            'Runtime default endpoint must be /__ui/event.',
        );
        self::assertMatchesRegularExpression(
            "/var\\s+CANONICAL_TRANSPORT_ENDPOINT\\s*=\\s*['\"]\\/__ui\\/event['\"]\\s*;/",
            $code,
            'Runtime canonical endpoint constant must point to /__ui/event.',
        );
    }

    #[Test]
    public function transport_canonical_wire_body_matches_ui_event_envelope_shape(): void
    {
        $code = $this->jsCode();
        // Canonical body shape for /__ui/event — must serialise a
        // UiEventEnvelope: schemaVersion, eventId, correlationId,
        // semanticEvent, signedContext, timestamp, payload. The shape
        // is the framework contract from
        // Semitexa\Ssr\Application\Service\UiEvent\UiEventEnvelope.
        self::assertMatchesRegularExpression(
            '/JSON\.stringify\s*\(\s*\{\s*'
                . 'schemaVersion:\s*ENVELOPE_SCHEMA_VERSION\s*,\s*'
                . 'eventId:\s*dispatchId\s*,\s*'
                . 'correlationId:\s*correlationId\s*,\s*'
                . 'semanticEvent:\s*semanticEvent\s*,\s*'
                . 'signedContext:\s*captured\.ctx\s*,\s*'
                . 'timestamp:\s*new\s+Date\(\)\.toISOString\(\)\s*,\s*'
                . 'payload:\s*payloadObj\s*'
                . '\}\s*\)/',
            $code,
            'Canonical /__ui/event branch must serialise exactly the UiEventEnvelope fields.',
        );
        // Schema version must be 1 (matches UiEventEnvelope::SCHEMA_VERSION).
        self::assertMatchesRegularExpression(
            '/var\s+ENVELOPE_SCHEMA_VERSION\s*=\s*1\s*;/',
            $code,
            'Envelope schema version must be 1.',
        );
        // eventId must be the same value used as the legacy
        // dispatchId — the adapter maps eventId → dispatchId 1:1 for
        // replay protection, and the legacy strict pattern is enforced
        // on the dispatchId side. The single generator call inside the
        // onCapture closure is the contract.
        self::assertMatchesRegularExpression(
            '/var\s+dispatchId\s*=\s*generateDispatchId\(\s*\)\s*;/',
            $code,
            'Same dispatchId generator must produce both the canonical eventId and the legacy dispatchId.',
        );
        // The correlationId helper exists and mints fresh ids.
        self::assertStringContainsString('function generateCorrelationId(', $code);
        self::assertMatchesRegularExpression(
            '/var\s+correlationId\s*=\s*generateCorrelationId\(\s*\)\s*;/',
            $code,
        );
        // semanticEvent is derived from captured.component + captured.event
        // — no per-call random strings, no routing fields.
        self::assertStringContainsString('function deriveSemanticEvent(', $code);
        self::assertStringContainsString("'.' + event", $code);
    }

    #[Test]
    public function transport_legacy_wire_body_for_ui_dispatch_endpoint_is_preserved(): void
    {
        $code = $this->jsCode();
        // Direct callers that opt into the compatibility endpoint
        // `/__ui/dispatch` (e.g. UiPlayground demos) still receive the
        // legacy `{ctx, dispatchId, payload}` body — that's the legacy
        // server decoder's contract.
        self::assertMatchesRegularExpression(
            '/JSON\.stringify\s*\(\s*\{\s*'
                . 'ctx:\s*captured\.ctx\s*,\s*'
                . 'dispatchId:\s*dispatchId\s*,\s*'
                . 'payload:\s*payloadObj\s*'
                . '\}\s*\)/',
            $code,
            'Legacy fallback branch must still serialise {ctx, dispatchId, payload: payloadObj}.',
        );
    }

    #[Test]
    public function transport_endpoint_branch_picks_canonical_for_default(): void
    {
        $code = $this->jsCode();
        // The branch between canonical and legacy body shapes is keyed
        // on the endpoint string itself, so the choice is local and
        // auditable.
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*endpoint\s*===\s*CANONICAL_TRANSPORT_ENDPOINT\s*\)/',
            $code,
            'Body-shape decision must branch on `endpoint === CANONICAL_TRANSPORT_ENDPOINT`.',
        );
    }

    #[Test]
    public function transport_wire_body_payload_object_seeding_unchanged(): void
    {
        $code = $this->jsCode();
        // The payloadObj is seeded with the captured value and nothing
        // else (other than the optional form snapshot — pinned
        // separately in EventRuntimeCrossFieldSnapshotTest).
        self::assertMatchesRegularExpression(
            '/var\s+payloadObj\s*=\s*\{\s*value:\s*captured\.value\s*\}\s*;/',
            $code,
            'Transport must seed payloadObj with exactly { value: captured.value } — no routing fields.',
        );
        // No serialization of the forbidden routing keys at top level
        // in either branch.
        foreach (['component', 'instance', 'part', 'event', 'handler', 'method', 'class', 'endpoint:', 'url:', 'action:'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden . ': captured.' . substr($forbidden, 0, -1),
                $code,
                'Transport must not serialize ' . $forbidden,
            );
        }
    }

    #[Test]
    public function transport_generates_dispatch_id_per_attempt_using_crypto(): void
    {
        $js = $this->js();
        $code = $this->jsCode();
        // The helper that mints the dispatchId.
        self::assertStringContainsString('function generateDispatchId(', $code);
        // Must use the cryptographically-strong RNG path (with a
        // non-crypto fallback). The primary path uses Web Crypto.
        self::assertStringContainsString('crypto.getRandomValues', $js);
        // 128 bits (16 bytes) of crypto random — same byte count for both
        // the dispatchId (which doubles as the canonical envelope's
        // eventId) and the correlationId. The literal `16` lives at the
        // helper-call sites; the helper itself is parameterised.
        self::assertStringContainsString("mintHexPrefixedId('ui_evt_', 16)", $code);
        self::assertStringContainsString('new Uint8Array(byteCount)', $code);
        // The minted id starts with the documented prefix so server logs
        // can identify frontend-originated dispatches.
        self::assertStringContainsString("'ui_evt_'", $js);
        // Each captured event mints its OWN dispatchId — `var dispatchId =
        // generateDispatchId();` lives inside the onCapture callback as
        // the first statement after the lifecycle comment block.
        self::assertMatchesRegularExpression(
            '/onCapture\s*\(\s*function\s*\(\s*captured\s*\)[^{]*\{[^}]*?var\s+dispatchId\s*=\s*generateDispatchId\(\s*\)/s',
            $code,
            'Transport must mint a fresh dispatchId for every captured event inside onCapture.',
        );
    }

    #[Test]
    public function transport_lifecycle_events_include_dispatch_id(): void
    {
        $code = $this->jsCode();
        // The dispatching/dispatched/failed CustomEvent details must
        // carry the dispatchId so consumers can correlate request+response.
        self::assertMatchesRegularExpression(
            '/emitTransportEvent\(\s*[\'"]semitexa:ui-event:dispatching[\'"][^)]*dispatchId:\s*dispatchId/s',
            $code,
        );
        self::assertMatchesRegularExpression(
            '/emitTransportEvent\(\s*[\'"]semitexa:ui-event:dispatched[\'"][^)]*dispatchId:\s*dispatchId/s',
            $code,
        );
        self::assertMatchesRegularExpression(
            '/emitTransportEvent\(\s*[\'"]semitexa:ui-event:failed[\'"][^)]*dispatchId:\s*dispatchId/s',
            $code,
        );
    }

    #[Test]
    public function transport_emits_lifecycle_custom_events(): void
    {
        $code = $this->jsCode();
        self::assertStringContainsString("'semitexa:ui-event:dispatching'", $code);
        self::assertStringContainsString("'semitexa:ui-event:dispatched'", $code);
        self::assertStringContainsString("'semitexa:ui-event:failed'", $code);
        // Patch lifecycle events fire once per patch in the response.
        self::assertStringContainsString("'semitexa:ui-patch:applied'", $code);
        self::assertStringContainsString("'semitexa:ui-patch:failed'", $code);
    }

    #[Test]
    public function patch_applier_uses_safe_dom_apis_only(): void
    {
        $code = $this->jsCode();
        // Hard bans for the patch applier: never `innerHTML`, never `eval`,
        // never `Function` constructor, never `document.write`, never script
        // injection helpers.
        self::assertStringNotContainsString('innerHTML', $code);
        self::assertStringNotContainsString('outerHTML', $code);
        self::assertStringNotContainsString('insertAdjacentHTML', $code);
        self::assertStringNotContainsString('document.write', $code);
        self::assertStringNotContainsString('eval(', $code);
        self::assertStringNotContainsString('new Function(', $code);
    }

    #[Test]
    public function patch_applier_targets_only_inside_component_instance_root(): void
    {
        $code = $this->jsCode();
        // Two queries from a *root element* — never from document — for
        // part lookup and patch-target lookup inside the applier.
        self::assertStringContainsString('rootEl.querySelector(', $code);
        // The only document.querySelector call in the applier reads the
        // component instance root by its instance-id attribute.
        self::assertMatchesRegularExpression(
            '/document\.querySelector\(\s*\n?\s*[\'"]\\[data-ui-component-instance-id="/',
            $code,
        );
    }

    #[Test]
    public function patch_applier_allow_list_includes_only_safe_ops(): void
    {
        $code = $this->jsCode();
        // Allowed: setText, setValue, setAttribute.
        self::assertMatchesRegularExpression('/ALLOWED_PATCH_OPS\s*=\s*\{\s*setText:\s*true\s*,\s*setValue:\s*true\s*,\s*setAttribute:\s*true\s*\}/', $code);
        // Banned ops must not appear as allowed keys.
        self::assertStringNotContainsString('setHtml:', $code);
        self::assertStringNotContainsString('execute:', $code);
        self::assertStringNotContainsString('eval:', $code);
        // setAttribute attribute allow-list.
        self::assertStringContainsString("'aria-invalid'", $code);
        self::assertStringContainsString("'aria-describedby'", $code);
        self::assertStringContainsString("'data-state'", $code);
        self::assertStringContainsString("'ui-state'", $code);
    }

    private static function tail(string $haystack, string $needle): string
    {
        $pos = strpos($haystack, $needle);
        return $pos === false ? '' : substr($haystack, $pos);
    }

    #[Test]
    public function sse_attach_is_opt_in_and_not_called_at_module_init(): void
    {
        $code = $this->jsCode();
        // The window.SemitexaUi.sse namespace must exist…
        self::assertMatchesRegularExpression('/sse:\s*\{/', $code);
        self::assertMatchesRegularExpression('/attach:\s*attachSse/', $code);
        // …and EventSource must NEVER be constructed outside attachSse.
        // We rely on a single `new EventSource(` callsite inside the
        // attachSse closure.
        self::assertSame(1, substr_count($code, 'new EventSource('));

        // Initialisation code (everything after `window.SemitexaUi = {`)
        // must not create an EventSource or open an SSE stream.
        $initSection = self::tail($code, 'window.SemitexaUi = {');
        self::assertNotSame('', $initSection);
        self::assertStringNotContainsString('new EventSource(', $initSection);
    }

    #[Test]
    public function sse_message_handler_delegates_to_existing_patch_applier(): void
    {
        $code = $this->jsCode();
        // The bridge that fans `ui.patch` SSE messages out to the safe
        // applier MUST call applyOnePatch — same DOM mutation engine
        // as the dispatch transport. A bug-shaped second engine that
        // bypasses applyOnePatch would silently violate the patch
        // contract; we pin this here.
        self::assertMatchesRegularExpression(
            '/function\s+applyOnePatchForSse[^{]*\{(?:.|\n)*?applyOnePatch\s*\(/s',
            $code,
            'SSE bridge must call the shared applyOnePatch implementation.',
        );
        // The canonical typed `ui.patch` envelope also routes through
        // the same applier — `applyCanonicalUiPatch` is the seam.
        self::assertMatchesRegularExpression(
            '/function\s+applyCanonicalUiPatch[^{]*\{(?:.|\n)*?applyOnePatchForSse\s*\(/s',
            $code,
            'Canonical typed `ui.patch` must route through the shared safe applier.',
        );
        // And no parallel innerHTML/eval/Function/etc.
        self::assertStringNotContainsString('innerHTML', $code);
        self::assertStringNotContainsString('eval(', $code);
    }

    #[Test]
    public function sse_bridge_listens_for_canonical_typed_ui_patch(): void
    {
        $code = $this->jsCode();
        // The `ui.patch` SSE listener must shape-detect between the
        // canonical typed envelope (`_type: 'ui.patch'`) and the legacy
        // `{v, patches}` shape — both share the event name, both must
        // route to safe appliers without a second mutation engine.
        self::assertMatchesRegularExpression(
            "/source\\.addEventListener\\(\\s*['\"]ui\\.patch['\"]/",
            $code,
        );
        self::assertMatchesRegularExpression(
            "/parsed\\._type\\s*===\\s*['\"]ui\\.patch['\"]/",
            $code,
            'ui.patch listener must shape-detect canonical _type for /__semitexa_kiss compatibility.',
        );
    }

    #[Test]
    public function sse_bridge_listens_for_canonical_ui_component_state(): void
    {
        $code = $this->jsCode();
        self::assertMatchesRegularExpression(
            "/source\\.addEventListener\\(\\s*['\"]ui\\.componentState['\"]/",
            $code,
        );
        // No DOM mutation consumer yet — surface as CustomEvent only.
        self::assertStringContainsString("'semitexa:ui-sse:component-state'", $code);
        self::assertMatchesRegularExpression(
            "/parsed\\._type\\s*!==\\s*['\"]ui\\.componentState['\"]/",
            $code,
            'ui.componentState listener must guard the typed discriminator.',
        );
    }

    #[Test]
    public function sse_bridge_listens_for_canonical_ui_error(): void
    {
        $code = $this->jsCode();
        self::assertMatchesRegularExpression(
            "/source\\.addEventListener\\(\\s*['\"]ui\\.error['\"]/",
            $code,
        );
        self::assertStringContainsString("'semitexa:ui-sse:error-message'", $code);
        self::assertMatchesRegularExpression(
            "/parsed\\._type\\s*!==\\s*['\"]ui\\.error['\"]/",
            $code,
            'ui.error listener must guard the typed discriminator.',
        );
    }

    #[Test]
    public function sse_attach_deduplicates_same_url_connections(): void
    {
        $code = $this->jsCode();
        // A second attachSse({url}) for the same URL must NOT open
        // another EventSource; doing so would duplicate every typed
        // listener.
        self::assertMatchesRegularExpression(
            '/for\s*\([^)]*ATTACHED_SSE_CONNECTIONS\.length/s',
            $code,
            'attachSse must check existing connections for same-URL dedupe.',
        );
        self::assertMatchesRegularExpression(
            '/ATTACHED_SSE_CONNECTIONS\[[a-zA-Z]+\]\.url\s*===\s*url/',
            $code,
            'Dedupe must key on the `url` field of the existing entry.',
        );
    }

    #[Test]
    public function runtime_auto_attaches_transport_when_platform_ui_manifests_present(): void
    {
        $code = $this->jsCode();
        self::assertStringContainsString('function maybeAutoAttachTransport(', $code);
        // Gating conditions: opt-out flag, manifests present, no prior
        // attach, fetch available.
        self::assertStringContainsString('window.SEMITEXA_UI_DISABLE_AUTOATTACH', $code);
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*parsedManifests\.length\s*===\s*0\s*\)/',
            $code,
            'Auto-attach must bail when no platform-ui manifests are parsed.',
        );
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*attachedTransports\.length\s*>\s*0\s*\)/',
            $code,
            'Auto-attach must not double-attach.',
        );
        self::assertMatchesRegularExpression(
            "/if\\s*\\(\\s*typeof\\s+fetch\\s*!==\\s*['\"]function['\"]\\s*\\)/",
            $code,
            'Auto-attach must require fetch availability.',
        );
        // Auto-attach must use the canonical default endpoint, not
        // /__ui/dispatch.
        self::assertMatchesRegularExpression(
            '/attachTransport\(\s*\{\s*endpoint:\s*DEFAULT_TRANSPORT_ENDPOINT\s*\}\s*\)/',
            $code,
            'Auto-attach must target DEFAULT_TRANSPORT_ENDPOINT (/__ui/event).',
        );
        // The hook must fire from both readyState branches in the
        // DOMContentLoaded / synchronous init paths. The match looks
        // for the call form (with trailing `;`) so the function
        // definition isn't counted.
        self::assertSame(
            2,
            substr_count($code, 'maybeAutoAttachTransport();'),
            'Auto-attach must be called from both DOM-loading and synchronous-init branches.',
        );
    }

    #[Test]
    public function runtime_auto_opens_canonical_kiss_when_meta_present(): void
    {
        $code = $this->jsCode();
        // Auto-open helper exists, sibling to maybeAutoAttachTransport.
        self::assertStringContainsString('function maybeAutoOpenSse(', $code);
        // Reads the canonical meta tag the server-side page-handler emits.
        self::assertStringContainsString(
            'meta[name="\' + SSE_SESSION_META_NAME + \'"]',
            $code,
        );
        // The meta name constant is the documented contract.
        self::assertMatchesRegularExpression(
            "/SSE_SESSION_META_NAME\\s*=\\s*['\"]semitexa-ui-sse-session['\"]\\s*;/",
            $code,
        );
        // Shared URL builder pins `?session_id=` + encodeURIComponent for
        // the id and `&mode=` + encodeURIComponent for the resolved mode.
        // Both halves use encodeURIComponent so a hand-edited meta tag
        // cannot smuggle extra query params even if the safe-shape guard
        // were bypassed. The transport-mode meta is constrained to two
        // values client-side; encoding it is defence in depth.
        self::assertMatchesRegularExpression(
            '/function\s+buildKissUrl\s*\(\s*sessionId\s*,\s*mode\s*\)\s*\{/',
            $code,
            'A shared URL builder is required so live and drain callsites cannot drift.',
        );
        self::assertMatchesRegularExpression(
            "/['\"]\\/__semitexa_kiss\\?session_id=['\"]\\s*\\+\\s*encodeURIComponent\\(\\s*sessionId\\s*\\)/",
            $code,
        );
        self::assertMatchesRegularExpression(
            "/['\"]&mode=['\"]\\s*\\+\\s*encodeURIComponent\\(\\s*mode\\s*\\)/",
            $code,
            'Resolved transport mode must be encoded into the KISS URL.',
        );
        // Live mode routes through attachSse with the shared builder —
        // the runtime never inlines a URL behind attachSse's back.
        self::assertMatchesRegularExpression(
            '/attachSse\(\s*\{\s*url:\s*buildKissUrl\(\s*sessionId\s*,\s*SSE_TRANSPORT_MODE_LIVE\s*\)\s*\}\s*\)/',
            $code,
            'Live mode must attach via attachSse({url: buildKissUrl(sessionId, SSE_TRANSPORT_MODE_LIVE)}).',
        );
    }

    #[Test]
    public function transport_mode_meta_constant_and_reader_are_present(): void
    {
        $code = $this->jsCode();
        self::assertMatchesRegularExpression(
            "/SSE_TRANSPORT_MODE_META_NAME\\s*=\\s*['\"]semitexa-ui-transport-mode['\"]\\s*;/",
            $code,
            'Transport mode meta name must match the server-side helper.',
        );
        self::assertMatchesRegularExpression(
            "/SSE_TRANSPORT_MODE_DRAIN\\s*=\\s*['\"]drain['\"]\\s*;/",
            $code,
        );
        self::assertMatchesRegularExpression(
            "/SSE_TRANSPORT_MODE_LIVE\\s*=\\s*['\"]live['\"]\\s*;/",
            $code,
        );
        // Reader function for the transport-mode meta. Unknown / missing
        // values MUST fall back to drain, never to live — that is the
        // client-side mirror of the server policy's hard default.
        self::assertStringContainsString('function readPageTransportMode(', $code);
        self::assertMatchesRegularExpression(
            '/return\s+SSE_TRANSPORT_MODE_DRAIN\s*;/',
            $code,
            'readPageTransportMode must fall back to drain.',
        );
    }

    #[Test]
    public function drain_mode_does_not_auto_open_on_dom_content_loaded(): void
    {
        $code = $this->jsCode();
        // The drain branch arms a listener instead of calling attachSse
        // directly. We pin the helper name + the absence of an attachSse
        // call in the synchronous drain code path inside maybeAutoOpenSse.
        self::assertStringContainsString('function armDrainOnDemand(', $code);
        self::assertMatchesRegularExpression(
            '/function\s+maybeAutoOpenSse[^{]*\{(?:.|\n)*?armDrainOnDemand\s*\(\s*sessionId\s*\)/s',
            $code,
            'Drain mode must arm the on-demand opener, not auto-open at load.',
        );
    }

    #[Test]
    public function drain_mode_opens_kiss_only_after_streamed_patch_count_positive(): void
    {
        $code = $this->jsCode();
        // The drain-on-demand listener subscribes to the existing
        // semitexa:ui-event:dispatched lifecycle event so we reuse the
        // canonical dispatch envelope — no new transport, no new event.
        self::assertMatchesRegularExpression(
            "/document\\.addEventListener\\(\\s*['\"]semitexa:ui-event:dispatched['\"]/",
            $code,
        );
        // The gate is the server-reported streamedPatchCount; absent or
        // non-positive means "inline patches only", and the runtime
        // MUST NOT open the KISS stream in that case.
        self::assertMatchesRegularExpression(
            '/detail\.response\.streamedPatchCount/',
            $code,
        );
        self::assertMatchesRegularExpression(
            '/streamed\s*<=\s*0/',
            $code,
            'Drain opener must bail when streamedPatchCount is not positive.',
        );
        // Drain URL carries mode=drain through the shared builder.
        self::assertMatchesRegularExpression(
            '/attachSse\(\s*\{\s*url:\s*buildKissUrl\(\s*sessionId\s*,\s*SSE_TRANSPORT_MODE_DRAIN\s*\)\s*\}\s*\)/',
            $code,
        );
    }

    #[Test]
    public function sse_close_event_tears_down_event_source_deterministically(): void
    {
        $code = $this->jsCode();
        // The `close` event from AsyncResourceSseServer signals "drain
        // queue flushed". Without an explicit source.close() the browser
        // would treat the server shutdown as a transient error and
        // reconnect, defeating the drain contract. We pin both the
        // listener registration and the in-handler teardown.
        self::assertMatchesRegularExpression(
            "/source\\.addEventListener\\(\\s*['\"]close['\"]/",
            $code,
            'A close-event listener must be registered.',
        );
        self::assertMatchesRegularExpression(
            "/source\\.addEventListener\\(\\s*['\"]close['\"](?:.|\\n)+?source\\.close\\(\\s*\\)/s",
            $code,
            'The close-event handler must call source.close() so drains terminate deterministically.',
        );
        // Closing must also remove the entry from the active-connections
        // table so a subsequent attachSse({url}) with the same URL can
        // open a fresh EventSource if needed.
        self::assertMatchesRegularExpression(
            "/source\\.addEventListener\\(\\s*['\"]close['\"](?:.|\\n)+?ATTACHED_SSE_CONNECTIONS\\.splice/s",
            $code,
        );
    }

    #[Test]
    public function sse_auto_open_is_gated_by_session_meta_and_eventsource_and_opt_out(): void
    {
        $code = $this->jsCode();
        // Same opt-out flag the transport auto-attach honours.
        self::assertMatchesRegularExpression(
            '/function\s+maybeAutoOpenSse[^{]*\{(?:.|\n)*?window\.SEMITEXA_UI_DISABLE_AUTOATTACH\s*===\s*true/s',
            $code,
            'Auto-open must honour SEMITEXA_UI_DISABLE_AUTOATTACH.',
        );
        // The manifest-count gate that previously short-circuited the
        // auto-open MUST be absent. Grid-only admin pages opt into
        // canonical KISS via the transport-mode meta tag but render
        // no component event manifests — gating on
        // `parsedManifests.length === 0` would silently skip the
        // EventSource open and break the live-refresh contract.
        $body = $this->extractFunctionBody($code, 'maybeAutoOpenSse');
        self::assertDoesNotMatchRegularExpression(
            '/parsedManifests\.length\s*===\s*0/',
            $body,
            'maybeAutoOpenSse must not gate on parsedManifests.length (grid-only pages render no manifests).',
        );
        // No EventSource → no auto-open (no console-only fallback path).
        self::assertMatchesRegularExpression(
            "/function\\s+maybeAutoOpenSse[^{]*\\{(?:.|\\n)*?typeof\\s+EventSource\\s*!==\\s*['\"]function['\"]/s",
            $code,
            'Auto-open must require EventSource availability.',
        );
        // Missing meta → no auto-open.
        self::assertMatchesRegularExpression(
            '/function\s+maybeAutoOpenSse[^{]*\{(?:.|\n)*?sessionId\s*===\s*null/s',
            $code,
            'Auto-open must bail when the meta tag is missing or unsafe.',
        );
    }

    /**
     * Extracts the body of the named top-level function from the JS
     * source so assertions can scope to that function only. Uses a
     * brace-counting scan starting from the `function NAME(` opening
     * brace — robust against nested braces inside the body, unlike a
     * single regex.
     */
    private function extractFunctionBody(string $code, string $functionName): string
    {
        $needle = 'function ' . $functionName;
        $start = strpos($code, $needle);
        self::assertNotFalse($start, "Function {$functionName} not found in event-runtime.js.");
        $openBrace = strpos($code, '{', $start);
        self::assertNotFalse($openBrace, "Opening brace for {$functionName} not found.");
        $depth = 0;
        for ($i = $openBrace; $i < strlen($code); $i++) {
            $c = $code[$i];
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($code, $openBrace, $i - $openBrace + 1);
                }
            }
        }
        self::fail("Unbalanced braces while extracting body of {$functionName}.");
    }

    #[Test]
    public function sse_session_id_safe_shape_pattern_is_enforced_client_side(): void
    {
        $code = $this->jsCode();
        // The client-side pattern must match the server-side
        // PlatformUiSseSessionState::SAFE_ID_PATTERN / the dispatcher's
        // SUBSCRIBER_CHANNEL_ID_PATTERN. Re-validation here is defence
        // in depth — a hand-edited meta tag with an unsafe id is
        // dropped client-side before encodeURIComponent runs.
        self::assertMatchesRegularExpression(
            '@SSE_SESSION_ID_SAFE_RE\s*=\s*/\^\[A-Za-z0-9\]\[A-Za-z0-9_-\]\{0,127\}\$/@',
            $code,
        );
    }

    #[Test]
    public function sse_auto_open_runs_after_auto_attach_transport_in_both_branches(): void
    {
        $code = $this->jsCode();
        // Both readyState branches call maybeAutoOpenSse() AFTER
        // maybeAutoAttachTransport(); transport must wire first because
        // the SSE auto-open relies on manifests already being parsed
        // by `scan()`.
        self::assertSame(
            2,
            substr_count($code, 'maybeAutoOpenSse();'),
            'Auto-open must be called from both DOM-loading and synchronous-init branches.',
        );
        // Order: AutoAttachTransport() then AutoOpenSse().
        $matched = preg_match_all(
            '/maybeAutoAttachTransport\(\);\s*maybeAutoOpenSse\(\);/',
            $code,
            $unused,
        );
        self::assertSame(
            2,
            $matched,
            'Auto-open must be called immediately after the transport auto-attach in both branches.',
        );
    }

    #[Test]
    public function sse_auto_open_does_not_construct_additional_event_source(): void
    {
        $code = $this->jsCode();
        // Auto-open must reuse attachSse rather than newing up its own
        // EventSource — the EventSource count invariant from
        // sse_attach_is_opt_in_and_not_called_at_module_init still
        // holds at exactly one.
        self::assertSame(1, substr_count($code, 'new EventSource('));
    }

    #[Test]
    public function sse_bridge_emits_lifecycle_custom_events(): void
    {
        $code = $this->jsCode();
        foreach ([
            'semitexa:ui-sse:connected',
            'semitexa:ui-sse:message',
            'semitexa:ui-sse:patch-applied',
            'semitexa:ui-sse:patch-failed',
            'semitexa:ui-sse:close',
            'semitexa:ui-sse:error',
        ] as $eventName) {
            self::assertStringContainsString("'" . $eventName . "'", $code);
        }
    }

    #[Test]
    public function sse_bridge_refuses_unknown_message_version(): void
    {
        $code = $this->jsCode();
        self::assertMatchesRegularExpression(
            '/SSE_MESSAGE_VERSION\s*=\s*1\b/',
            $code,
        );
        // Unknown version branch must emit an error and bail without
        // calling the patch applier.
        self::assertMatchesRegularExpression(
            '/parsed\.v\s*!==\s*SSE_MESSAGE_VERSION/',
            $code,
        );
    }

    #[Test]
    public function sse_bridge_handles_missing_event_source_gracefully(): void
    {
        $code = $this->jsCode();
        self::assertMatchesRegularExpression(
            "/typeof\\s+EventSource\\s*!==\\s*['\"]function['\"]/",
            $code,
            'sse.attach must check EventSource availability and bail gracefully.',
        );
    }

    #[Test]
    public function runtime_does_not_contain_dispatch_or_verify_calls(): void
    {
        $code = $this->jsCode();
        self::assertStringNotContainsString('UiInteractionDispatcher', $code);
        // The runtime treats `ctx` as opaque — it MUST NOT try to decode or
        // verify the signed context client-side.
        self::assertStringNotContainsString('hmac', strtolower($code));
        self::assertStringNotContainsString('crypto.subtle', $code);
    }

    #[Test]
    public function runtime_does_not_mutate_state_or_default_events(): void
    {
        $code = $this->jsCode();
        // Capture-only by default: NEVER stopPropagation, NEVER
        // stopImmediatePropagation — descendants must still see their
        // own events.
        self::assertStringNotContainsString('stopPropagation', $code);
        self::assertStringNotContainsString('stopImmediatePropagation', $code);

        // preventDefault has exactly ONE narrow allow: the managed
        // platform.form submit event, where the browser's default
        // would navigate away before the dispatcher could respond.
        // The single callsite is conditional on
        // `nativeEvent === 'submit' && partEl.tagName === 'FORM'`.
        self::assertSame(
            1,
            substr_count($code, 'ev.preventDefault('),
            'preventDefault must be called from exactly one narrowly-scoped site.',
        );
        self::assertMatchesRegularExpression(
            "/nativeEvent\\s*===\\s*'submit'\\s*&&\\s*partEl\\.tagName\\s*===\\s*'FORM'/",
            $code,
            'preventDefault must be guarded by the submit-on-<form> check.',
        );
    }

    #[Test]
    public function runtime_uses_capture_phase_for_delegation(): void
    {
        $code = $this->jsCode();
        // Document-level delegation in capture phase is the documented
        // contract — it lets the runtime see events before any
        // descendant handler can stop propagation.
        // The closure body wraps multiple `)` chars so a strict regex
        // would have to be permissive; we instead require both pieces
        // of the contract to exist in the code.
        self::assertStringContainsString('document.addEventListener(', $code);
        self::assertMatchesRegularExpression('/\},\s*true\)\s*;/', $code);
    }

    #[Test]
    public function runtime_publishes_custom_event_for_local_capture(): void
    {
        $js = $this->js();
        self::assertStringContainsString("CustomEvent('semitexa:ui-event:captured'", $js);
        self::assertStringContainsString('bubbles: false', $js);
    }

    #[Test]
    public function assets_json_declares_event_runtime_with_global_scope(): void
    {
        $manifest = $this->manifest();

        self::assertIsArray($manifest['include']);
        self::assertContains('js/*.js', $manifest['include']['js'] ?? []);

        $overrides = $manifest['overrides'];
        self::assertArrayHasKey('js/event-runtime.js', $overrides);

        $entry = $overrides['js/event-runtime.js'];
        self::assertSame('global', $entry['scope']);
        self::assertSame('body', $entry['position']);
        self::assertIsInt($entry['priority']);
        self::assertArrayHasKey('attributes', $entry);
        self::assertTrue($entry['attributes']['defer'] ?? false);
    }

    #[Test]
    public function runtime_understands_manifest_schema_version_1(): void
    {
        $js = $this->js();
        self::assertMatchesRegularExpression(
            '/MANIFEST_VERSION\s*=\s*1\b/',
            $js,
        );
    }

    #[Test]
    public function runtime_prefers_data_ui_part_with_ui_alias_fallback(): void
    {
        $code = $this->jsCode();
        // Canonical lookup first: `[data-ui-part="<partName>"]`.
        self::assertMatchesRegularExpression(
            '/\[data-ui-part="\'\s*\+\s*safe\s*\+\s*\'"\]/',
            $code,
            'Runtime must query for data-ui-part by part name as its primary lookup.',
        );
        // Back-compat fallback: `[ui="<partName>"]`.
        self::assertMatchesRegularExpression(
            '/\[ui="\'\s*\+\s*safe\s*\+\s*\'"\]/',
            $code,
            'Runtime must keep a back-compat fallback to ui="…" for legacy templates.',
        );
        // The data-ui-part lookup must appear BEFORE the ui= fallback in
        // the function body — the order is the contract.
        $primaryPos = strpos($code, '[data-ui-part="');
        $fallbackPos = strpos($code, '[ui="');
        self::assertNotFalse($primaryPos);
        self::assertNotFalse($fallbackPos);
        self::assertLessThan(
            $fallbackPos,
            $primaryPos,
            'data-ui-part lookup must precede ui="…" fallback in findPartElement().',
        );
    }
}
