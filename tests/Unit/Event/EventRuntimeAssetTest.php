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
    public function transport_wire_body_includes_ctx_dispatch_id_and_payload(): void
    {
        $code = $this->jsCode();
        // Locate the JSON.stringify call that builds the wire body.
        // Must serialize ctx + dispatchId + payload, with payload built
        // up from `payloadObj` (the in-scope variable seeded with the
        // captured value and optionally the form snapshot — see
        // EventRuntimeCrossFieldSnapshotTest for the snapshot pin).
        self::assertMatchesRegularExpression(
            '/JSON\.stringify\s*\(\s*\{\s*'
                . 'ctx:\s*captured\.ctx\s*,\s*'
                . 'dispatchId:\s*dispatchId\s*,\s*'
                . 'payload:\s*payloadObj\s*'
                . '\}\s*\)/',
            $code,
            'Transport must serialize exactly {ctx, dispatchId, payload: payloadObj} — no routing fields.',
        );
        // The payloadObj is seeded with the captured value and nothing
        // else (other than the optional form snapshot — pinned
        // separately).
        self::assertMatchesRegularExpression(
            '/var\s+payloadObj\s*=\s*\{\s*value:\s*captured\.value\s*\}\s*;/',
            $code,
            'Transport must seed payloadObj with exactly { value: captured.value } — no routing fields.',
        );
        // No serialization of the forbidden routing keys at top level.
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
        self::assertStringContainsString('Uint8Array(16)', $js);
        // The minted id starts with the documented prefix so server logs
        // can identify frontend-originated dispatches.
        self::assertStringContainsString("'ui_evt_'", $js);
        // Each captured event mints its OWN dispatchId — `var dispatchId =
        // generateDispatchId();` lives inside the onCapture callback.
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
        // And no parallel innerHTML/eval/Function/etc.
        self::assertStringNotContainsString('innerHTML', $code);
        self::assertStringNotContainsString('eval(', $code);
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
