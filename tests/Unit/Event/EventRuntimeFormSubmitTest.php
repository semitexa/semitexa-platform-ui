<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Asset-level invariants for the FormComponent submit capture path
 * inside event-runtime.js. Behavioural verification (preventDefault
 * fires, snapshot reaches the wire, response patches apply) happens
 * in the playground and via curl; these pins lock the security and
 * scope rules so a future contributor cannot widen them
 * accidentally.
 */
final class EventRuntimeFormSubmitTest extends TestCase
{
    private const PACKAGE_ROOT = __DIR__ . '/../../..';
    private const JS_RELATIVE = '/src/Application/Static/js/event-runtime.js';

    private function js(): string
    {
        $path = self::PACKAGE_ROOT . self::JS_RELATIVE;
        $contents = @file_get_contents($path);
        if ($contents === false) {
            $this->fail('event-runtime.js missing at ' . $path);
        }
        return $contents;
    }

    private function jsCode(): string
    {
        $src = $this->js();
        $stripped = preg_replace('!/\*.*?\*/!s', '', $src) ?? $src;
        $stripped = preg_replace('!(^|\s)//[^\n]*!', '$1', $stripped) ?? $stripped;
        return $stripped;
    }

    #[Test]
    public function prevent_default_is_scoped_to_submit_on_form_only(): void
    {
        $code = $this->jsCode();
        // Single, narrow allow for preventDefault: the managed
        // platform.form submit. No other native event has its
        // default suppressed.
        self::assertSame(
            1,
            substr_count($code, 'ev.preventDefault('),
            'preventDefault must have exactly one callsite.',
        );
        self::assertMatchesRegularExpression(
            "/nativeEvent\\s*===\\s*'submit'\\s*&&\\s*partEl\\.tagName\\s*===\\s*'FORM'/",
            $code,
        );
        // Negative pin: no preventDefault tied to input / change /
        // blur / click events.
        self::assertStringNotContainsString("'input'", $this->bandOfCode($code, 'preventDefault'));
        self::assertStringNotContainsString("'change'", $this->bandOfCode($code, 'preventDefault'));
        self::assertStringNotContainsString("'click'", $this->bandOfCode($code, 'preventDefault'));
    }

    #[Test]
    public function submit_capture_runs_through_existing_event_delegation(): void
    {
        $code = $this->jsCode();
        // No bespoke `addEventListener('submit', ...)` literal — the
        // submit native event must be picked up by the same
        // `document.addEventListener(nativeEvent, ..., true)`
        // dispatcher that handles every other declared event. A
        // dedicated listener would bypass the manifest-driven scope.
        self::assertStringNotContainsString("addEventListener('submit'", $code);
        self::assertStringNotContainsString('addEventListener("submit"', $code);
        // The listener is capture-phase so submit is observed before
        // the browser's default action — preventDefault inside the
        // capture-phase handler is the documented contract.
        self::assertMatchesRegularExpression('/\},\s*true\)\s*;/', $code);
    }

    #[Test]
    public function snapshot_collector_handles_form_root_as_captured_instance(): void
    {
        $code = $this->jsCode();
        // Submit case: the captured instance IS the form root. The
        // collector must accept this path so payload.form.values
        // reaches the server on submit just like on input.change.
        self::assertMatchesRegularExpression(
            '/instanceEl\.matches\(\s*\n?\s*'
                . '[\'"]\[data-ui-form-aggregate="1"\]\[data-ui-component-instance-id\][\'"]\s*\)/s',
            $code,
        );
        // Field case: the captured instance is INSIDE a form root,
        // resolve via parentNode.closest.
        self::assertMatchesRegularExpression(
            '/instanceEl\.parentNode\.closest\(\s*\n?\s*'
                . '[\'"]\[data-ui-form-aggregate="1"\]\[data-ui-component-instance-id\][\'"]\s*\)/s',
            $code,
        );
    }

    #[Test]
    public function submit_does_not_introduce_a_second_transport_endpoint(): void
    {
        $code = $this->jsCode();
        // No separate `/submit` / `/__ui/submit` endpoint. Submit
        // goes through the same /__ui/dispatch as input.change.
        self::assertStringNotContainsString('/__ui/submit', $code);
        self::assertStringNotContainsString("'/submit'", $code);
        // Submit introduces no new transport: the only fetch callsites are
        // the existing attachTransport bridge and the multiplex
        // subscribe-control POST (postSseControl, Phase 3) — both shared
        // infrastructure, neither submit-specific.
        self::assertSame(2, substr_count($code, 'fetch('));
    }

    #[Test]
    public function no_new_unsafe_apis_introduced(): void
    {
        $code = $this->jsCode();
        // The submit capture path must not have introduced new
        // string-execution / DOM-write APIs.
        self::assertStringNotContainsString('innerHTML', $code);
        self::assertStringNotContainsString('outerHTML', $code);
        self::assertStringNotContainsString('insertAdjacentHTML', $code);
        self::assertStringNotContainsString('document.write', $code);
        self::assertStringNotContainsString('eval(', $code);
        self::assertStringNotContainsString('new Function(', $code);
    }

    #[Test]
    public function input_change_capture_is_unchanged(): void
    {
        $code = $this->jsCode();
        // Pin the input.change → field-aggregate path is still
        // wired the way the previous slices documented. If a
        // future contributor breaks the delegated capture, this
        // test fires.
        self::assertStringContainsString('handleNativeEvent', $code);
        self::assertMatchesRegularExpression(
            '/findInstanceRoot\s*\(\s*ev\.target\s*\)/',
            $code,
        );
    }

    /**
     * Helper: return a 120-char window of code around the first
     * occurrence of $anchor. Used by the preventDefault scope tests
     * to assert that strings like `'input'` / `'click'` don't show
     * up near a preventDefault callsite.
     */
    private function bandOfCode(string $code, string $anchor): string
    {
        $pos = strpos($code, $anchor);
        if ($pos === false) {
            return '';
        }
        $start = max(0, $pos - 60);
        return substr($code, $start, 120);
    }
}
