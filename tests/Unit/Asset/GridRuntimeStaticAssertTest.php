<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Asset;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Static assertions on the package's `grid-runtime.js` source.
 *
 * The runtime is one file; we don't have a JS test harness in this
 * repo. These checks are intentionally grep-style — they pin the
 * critical safety / API invariants so a regression in a future edit
 * is loud at PHPUnit time. The patterns deliberately match the
 * RUNTIME BODY only, not docblock comments that mention the
 * forbidden primitives as part of explaining what we don't do.
 *
 * Pins:
 *
 *   - the asset file exists at the documented location;
 *   - it is listed in the package assets.json;
 *   - it does not call `innerHTML = …` on any element;
 *   - it does not call `eval(...)` or invoke the Function
 *     constructor;
 *   - it dispatches the SemitexaUi.grid namespace;
 *   - it listens for the SSE `patch-applied` CustomEvent;
 *   - it uses `textContent` (the canonical XSS-safe write path).
 */
final class GridRuntimeStaticAssertTest extends TestCase
{
    private const RUNTIME_PATH = __DIR__ . '/../../../src/Application/Static/js/grid-runtime.js';
    private const ASSETS_JSON_PATH = __DIR__ . '/../../../src/Application/Static/assets.json';

    private function loadRuntime(): string
    {
        self::assertFileExists(self::RUNTIME_PATH, 'grid-runtime.js must exist at the documented path.');
        $source = file_get_contents(self::RUNTIME_PATH);
        self::assertIsString($source);
        return $source;
    }

    /**
     * Strip block + line comments so the safety greps below only
     * see the actual runtime code. The grid-runtime docblock
     * legitimately mentions "innerHTML" + "eval" + "Function
     * constructor" inside a doc comment that explains what the
     * runtime intentionally does NOT do — those mentions must not
     * trip the static asserts.
     */
    private function strippedRuntime(): string
    {
        $source = $this->loadRuntime();
        // Remove /** … */ and /* … */ block comments.
        $source = preg_replace('@/\*.*?\*/@s', '', $source) ?? $source;
        // Remove // line comments through end-of-line.
        $source = preg_replace('@//[^\n]*@', '', $source) ?? $source;
        return $source;
    }

    #[Test]
    public function runtime_file_exists(): void
    {
        self::assertFileExists(self::RUNTIME_PATH);
    }

    #[Test]
    public function runtime_is_declared_in_package_assets_json(): void
    {
        self::assertFileExists(self::ASSETS_JSON_PATH);
        $raw = file_get_contents(self::ASSETS_JSON_PATH);
        self::assertIsString($raw);
        $manifest = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertArrayHasKey('overrides', $manifest);
        self::assertArrayHasKey(
            'js/grid-runtime.js',
            $manifest['overrides'],
            'grid-runtime.js must be declared as an override in assets.json.',
        );
        $override = $manifest['overrides']['js/grid-runtime.js'];
        self::assertSame('global', $override['scope']  ?? null);
        self::assertSame('body',   $override['position'] ?? null);
        self::assertTrue(($override['attributes']['defer'] ?? false) === true);
    }

    #[Test]
    public function runtime_body_does_not_assign_innerHTML(): void
    {
        $body = $this->strippedRuntime();
        // Forbid any `.innerHTML = …` or `innerHTML=` write. The
        // runtime is allowed to MENTION the word "innerHTML" in
        // comments (already stripped above), but never as an
        // actual property assignment.
        self::assertDoesNotMatchRegularExpression(
            '/\binnerHTML\s*=/',
            $body,
            'grid-runtime.js must never assign innerHTML — use textContent + createElement instead.',
        );
    }

    #[Test]
    public function runtime_body_does_not_call_eval(): void
    {
        $body = $this->strippedRuntime();
        self::assertDoesNotMatchRegularExpression(
            '/\beval\s*\(/',
            $body,
            'grid-runtime.js must not call eval().',
        );
    }

    #[Test]
    public function runtime_body_does_not_use_function_constructor(): void
    {
        $body = $this->strippedRuntime();
        // The Function constructor is invoked as `new Function(...)`
        // — pin that exact pattern.
        self::assertDoesNotMatchRegularExpression(
            '/\bnew\s+Function\s*\(/',
            $body,
            'grid-runtime.js must not use the Function constructor.',
        );
    }

    #[Test]
    public function runtime_body_does_not_use_document_write(): void
    {
        $body = $this->strippedRuntime();
        self::assertDoesNotMatchRegularExpression(
            '/document\s*\.\s*write\s*\(/',
            $body,
            'grid-runtime.js must not use document.write().',
        );
    }

    #[Test]
    public function runtime_registers_semitexaui_grid_namespace(): void
    {
        $source = $this->loadRuntime();
        self::assertMatchesRegularExpression(
            '/window\.SemitexaUi\.grid\s*=/',
            $source,
            'grid-runtime.js must expose SemitexaUi.grid.',
        );
    }

    #[Test]
    public function runtime_listens_for_canonical_component_state_event(): void
    {
        $source = $this->loadRuntime();
        self::assertStringContainsString(
            "semitexa:ui-sse:component-state",
            $source,
            'grid-runtime.js must subscribe to the canonical ui.componentState document event dispatched by event-runtime.js.',
        );
    }

    #[Test]
    public function runtime_no_longer_references_legacy_sse_patch_applied(): void
    {
        // The legacy /__ui/stream patch-applied event is replaced by
        // the canonical KISS componentState document event. Pin the
        // removal so a future regression cannot reintroduce the
        // legacy listener silently.
        $source = $this->loadRuntime();
        self::assertStringNotContainsString(
            'semitexa:ui-sse:patch-applied',
            $source,
            'grid-runtime.js must not reference the legacy patch-applied event after the canonical refresh migration.',
        );
        self::assertStringNotContainsString(
            'data-ui-grid-sse-url',
            $source,
            'grid-runtime.js must not read the legacy sseUrl attribute after the canonical refresh migration.',
        );
    }

    #[Test]
    public function runtime_uses_textcontent_for_dom_writes(): void
    {
        $source = $this->loadRuntime();
        self::assertStringContainsString(
            '.textContent',
            $source,
            'grid-runtime.js must render cells via textContent.',
        );
    }

    #[Test]
    public function runtime_uses_createElement_for_table_rows(): void
    {
        $source = $this->loadRuntime();
        self::assertStringContainsString(
            "document.createElement('tr')",
            $source,
            'grid-runtime.js must build rows via createElement.',
        );
        self::assertStringContainsString(
            "document.createElement('td')",
            $source,
            'grid-runtime.js must build cells via createElement.',
        );
    }

    #[Test]
    public function runtime_auto_submits_filter_form_on_change_for_opt_in_controls(): void
    {
        // Pins the page-size-selector fix: the runtime registers a
        // change listener on the filter form, and only elements that
        // carry `data-ui-grid-reload-on-change` participate (so
        // typing into the free-text `q` input does NOT auto-fire on
        // every keystroke). Both invariants must hold together.
        $body = $this->strippedRuntime();

        self::assertMatchesRegularExpression(
            "/formEl\.addEventListener\(\s*'change'/",
            $body,
            'grid-runtime.js must register a change listener on the filter form so opt-in controls (e.g. the page-size select) re-fetch the grid the moment their value changes.',
        );
        self::assertStringContainsString(
            'data-ui-grid-reload-on-change',
            $body,
            'grid-runtime.js must read the data-ui-grid-reload-on-change opt-in marker so it only auto-reloads for elements that explicitly request it.',
        );
    }

    #[Test]
    public function runtime_resets_cursor_when_a_change_triggered_reload_fires(): void
    {
        // Auto-submit-on-change MUST go through the same state-read
        // + cursor-reset path the explicit submit handler uses;
        // otherwise the cursor minted under the previous page size
        // would leak into the new request and trip the cursor
        // fingerprint / shape guard. We pin this by checking that
        // the shared reload path nulls the cursor.
        $body = $this->strippedRuntime();
        self::assertMatchesRegularExpression(
            '/state\.cursor\s*=\s*null/',
            $body,
            'grid-runtime.js must reset state.cursor=null in the shared form-driven reload path so changing the page size starts a fresh first-page request.',
        );
    }

    #[Test]
    public function runtime_maintains_cursor_history_stack(): void
    {
        // Previous-button support is implemented entirely client-side
        // on top of the existing forward-only cursor envelope. The
        // runtime keeps a per-criteria stack of cursors (index 0 is
        // always null = page 1), pushes onto it on Next, and pops /
        // jumps via Previous + visited-page-button clicks. Pin the
        // shape so a future refactor that drops the stack regresses
        // pagination UX immediately.
        $body = $this->strippedRuntime();
        // Initial declaration lives inside the `state` object literal
        // as `cursors: [null]`; the reset path writes through the
        // property accessor as `state.cursors = [null]`. Either
        // surface is acceptable as the seed.
        self::assertMatchesRegularExpression(
            '/(?:state\.)?cursors\s*[:=]\s*\[\s*null\s*\]/',
            $body,
            'grid-runtime.js must seed cursors as [null] so page 1 always has an implicit-null inbound cursor.',
        );
        self::assertMatchesRegularExpression(
            '/state\.cursors\.push\s*\(/',
            $body,
            'grid-runtime.js must push the response nextCursor onto state.cursors on forward navigation.',
        );
    }

    #[Test]
    public function runtime_resets_pagination_history_on_criteria_change(): void
    {
        // Criteria changes — submit, auto-change, sort-click —
        // MUST clear the history stack so Previous / numbered
        // buttons don't offer cross-criteria cursors that the
        // server-side fingerprint guard would reject (or, for limit
        // changes, that would mid-stream the result set). One shared
        // resetter is the single source of truth.
        $body = $this->strippedRuntime();
        self::assertMatchesRegularExpression(
            '/function\s+resetPaginationHistory\s*\(\s*\)/',
            $body,
            'grid-runtime.js must define a resetPaginationHistory() helper that wipes state.cursors and state.page.',
        );
        $resetCallCount = preg_match_all('/resetPaginationHistory\s*\(\s*\)/', $body);
        self::assertGreaterThanOrEqual(
            3,
            $resetCallCount,
            'grid-runtime.js must call resetPaginationHistory() in at least three places: the shared form-state path, the sort-header click handler, and the helper definition itself — so every criteria-change route resets pagination.',
        );
    }

    #[Test]
    public function runtime_handles_previous_button_and_visited_page_buttons(): void
    {
        // The pagination footer adds Previous + visited-page numbered
        // buttons. We pin both the delegated click matcher (so a
        // future template tweak doesn't accidentally orphan the
        // listeners) and the page-rendering loop (so visited-page
        // buttons are still produced via createElement, not innerHTML
        // or string concat).
        $body = $this->strippedRuntime();
        self::assertStringContainsString(
            "closest('[data-ui-grid-prev]')",
            $body,
            'grid-runtime.js must delegate clicks on the Previous button via closest().',
        );
        self::assertStringContainsString(
            "closest('[data-ui-grid-page]')",
            $body,
            'grid-runtime.js must delegate clicks on visited-page buttons via closest().',
        );
        self::assertStringContainsString(
            "document.createElement('button')",
            $body,
            'grid-runtime.js must build visited-page buttons via document.createElement(), not innerHTML.',
        );
    }

    #[Test]
    public function runtime_writes_current_page_indicator_with_textcontent(): void
    {
        // Page indicator updates use textContent so untrusted-ish
        // labels can never become markup. Pin the literal label
        // composition + a textContent write near it.
        $body = $this->strippedRuntime();
        self::assertMatchesRegularExpression(
            "/'Page '\s*\+\s*state\.page/",
            $body,
            'grid-runtime.js must render the page indicator as "Page <N>" via string concat into textContent.',
        );
        self::assertStringContainsString(
            'indicatorEl.textContent =',
            $body,
            'grid-runtime.js must write the page indicator via textContent (no innerHTML).',
        );
    }

    #[Test]
    public function runtime_reads_pagination_window_size_data_attribute(): void
    {
        // The pagination footer no longer renders every visited page
        // — it renders only a sliding window. Pin that the runtime:
        //   (a) reads the server-rendered data attribute;
        //   (b) clamps invalid values back to the default;
        //   (c) drives the page-button loop from a computed range,
        //       not from `1..visited`.
        $body = $this->strippedRuntime();
        self::assertStringContainsString(
            "data-ui-grid-pagination-window-size",
            $body,
            'grid-runtime.js must read the data-ui-grid-pagination-window-size attribute from the grid root.',
        );
        self::assertMatchesRegularExpression(
            '/DEFAULT_PAGE_WINDOW\s*=\s*7\b/',
            $body,
            'grid-runtime.js must default the page window to 7.',
        );
        self::assertMatchesRegularExpression(
            '/MIN_PAGE_WINDOW\s*=\s*1\b/',
            $body,
            'grid-runtime.js must clamp the page window minimum to 1.',
        );
        self::assertMatchesRegularExpression(
            '/MAX_PAGE_WINDOW\s*=\s*25\b/',
            $body,
            'grid-runtime.js must clamp the page window maximum to 25.',
        );
    }

    #[Test]
    public function runtime_defines_and_exposes_compute_page_window_helper(): void
    {
        // The sliding-window calculation is a pure helper so it can
        // be reasoned about (and, in a future Node-side test harness,
        // exercised directly via window.SemitexaUi.grid.computePage
        // Window). Pin both the helper definition AND its presence
        // on the public namespace.
        $body = $this->strippedRuntime();
        self::assertMatchesRegularExpression(
            '/function\s+computePageWindow\s*\(/',
            $body,
            'grid-runtime.js must define a pure computePageWindow() helper for the sliding pagination window.',
        );
        self::assertStringContainsString(
            'computePageWindow: computePageWindow',
            $body,
            'grid-runtime.js must expose computePageWindow on window.SemitexaUi.grid so the helper can be exercised in a console / future Node-side harness.',
        );
    }

    #[Test]
    public function runtime_page_button_loop_uses_window_range_not_full_visited_list(): void
    {
        // The regression we are guarding against: a future refactor
        // dropping the window and iterating `for (p = 1; p <= visited;
        // p++)` again. Pin that the rendering loop iterates a range
        // computed from computePageWindow().
        $body = $this->strippedRuntime();
        self::assertMatchesRegularExpression(
            '/computePageWindow\s*\(\s*state\.page\s*,\s*visited\s*,\s*paginationWindowSize\s*\)/',
            $body,
            'grid-runtime.js must drive the page-button loop from computePageWindow(state.page, visited, paginationWindowSize).',
        );
        self::assertMatchesRegularExpression(
            '/for\s*\(\s*var\s+p\s*=\s*range\.start\s*;\s*p\s*<=\s*range\.end\s*;\s*p\+\+\s*\)/',
            $body,
            'grid-runtime.js must iterate the page-button loop from range.start to range.end (inclusive), not 1..visited.',
        );
    }

    #[Test]
    public function runtime_ellipsis_nodes_are_aria_hidden_spans_with_no_click_target(): void
    {
        // Ellipsis markers are decorative only — they MUST NOT be
        // buttons and MUST NOT carry [data-ui-grid-page], otherwise
        // the click delegator would resolve them to a navigation
        // and silently re-fetch the current page (or worse). The
        // builder runs through createElement, sets aria-hidden, and
        // never sets data-ui-grid-page on the node.
        $body = $this->strippedRuntime();
        self::assertMatchesRegularExpression(
            '/function\s+buildEllipsisNode\s*\(/',
            $body,
            'grid-runtime.js must define a buildEllipsisNode() helper.',
        );
        self::assertStringContainsString(
            "document.createElement('span')",
            $body,
            'grid-runtime.js must build the ellipsis marker via createElement(span) — never a button.',
        );
        self::assertStringContainsString(
            "setAttribute('aria-hidden', 'true')",
            $body,
            'grid-runtime.js must mark the ellipsis aria-hidden so screen readers / clicks skip it.',
        );
        // The ellipsis builder body MUST NOT touch data-ui-grid-page
        // — that attribute is what the click delegator looks for via
        // closest('[data-ui-grid-page]'). Grep the runtime for that
        // attribute occurring near setAttribute on a span builder; if
        // a future refactor adds it, this pin is loud.
        // We only forbid the specific bad combination: a setAttribute
        // call inside the ellipsis builder that writes the
        // data-ui-grid-page key.
        $offset = strpos($body, 'function buildEllipsisNode');
        self::assertNotFalse($offset, 'Expected to find buildEllipsisNode definition.');
        $tail = substr($body, $offset, 600);
        self::assertStringNotContainsString(
            "data-ui-grid-page",
            $tail,
            'buildEllipsisNode() must NOT set data-ui-grid-page — the ellipsis must never be a navigation target.',
        );
    }

    #[Test]
    public function runtime_arms_lost_frame_watchdog_with_http_fallback(): void
    {
        // Live-mode gestures await a `ui.componentState` SSE frame; if it
        // is lost (stream dropped during a reconnect) nothing re-renders.
        // A bounded watchdog falls back to the deterministic HTTP path and
        // the awaited frame disarms it.
        $body = $this->strippedRuntime();
        self::assertMatchesRegularExpression(
            '/function\s+armFrameWatchdog\s*\(/',
            $body,
            'grid-runtime.js must define armFrameWatchdog() so a lost SSE frame self-heals.',
        );
        self::assertMatchesRegularExpression(
            '/setTimeout\s*\(/',
            $body,
            'armFrameWatchdog must use setTimeout to bound the wait for the SSE frame.',
        );
        self::assertStringContainsString(
            'fetchLegacyAndRender();',
            $body,
            'the watchdog timeout must fall back to fetchLegacyAndRender().',
        );
        self::assertStringContainsString(
            'clearFrameWatchdog();',
            $body,
            'the component-state render path must clear the watchdog when the frame arrives.',
        );
    }

    #[Test]
    public function runtime_self_heals_on_sse_reconnect(): void
    {
        $source = $this->loadRuntime();
        self::assertStringContainsString(
            "addEventListener('semitexa:ui-sse:reconnected'",
            $source,
            'grid-runtime.js must re-pull the current view when the shared SSE stream reconnects.',
        );
    }

    #[Test]
    public function runtime_does_not_reference_nonexistent_dispatched_listener(): void
    {
        // Historical bug: a comment claimed a `semitexa:ui-event:dispatched`
        // listener consumed the dispatch response and called renderPage().
        // No such listener ever existed — the row data arrives over the
        // ui.componentState SSE frame. Pin the removal so the misleading
        // claim cannot creep back. (The distinct `dispatching` event the
        // runtime does listen to is unaffected — it is a different name.)
        $source = $this->loadRuntime();
        self::assertStringNotContainsString(
            'semitexa:ui-event:dispatched',
            $source,
            'grid-runtime.js must not reference a semitexa:ui-event:dispatched listener — it does not exist.',
        );
    }
}
