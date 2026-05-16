<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Asset-level invariants for the FormComponent client-local aggregation
 * layer inside event-runtime.js.
 *
 * The aggregation logic is JavaScript and runs in the browser; PHP can
 * only verify the file's structural shape — which functions exist,
 * which DOM hooks are used, and which DOM mutation engine the patches
 * are funnelled through. Browser-level behaviour (state transitions,
 * cross-form isolation, repeated update collapsing) is covered by the
 * Playground demo's manual verification and by future E2E.
 */
final class EventRuntimeFormAggregateTest extends TestCase
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

    /**
     * Returns the JS with /* … *\/ blocks stripped, so structural
     * assertions are not tripped by docstring text that describes what
     * the runtime intentionally does NOT contain.
     */
    private function jsCode(): string
    {
        $src = $this->js();
        $stripped = preg_replace('!/\*.*?\*/!s', '', $src) ?? $src;
        $stripped = preg_replace('!(^|\s)//[^\n]*!', '$1', $stripped) ?? $stripped;
        return $stripped;
    }

    #[Test]
    public function form_aggregate_entry_point_runs_after_each_dispatch(): void
    {
        $code = $this->jsCode();

        // The dispatch transport must call updateFormAggregate after
        // applyResponsePatches. Without that ordering, the aggregate
        // would race the field's own DOM updates.
        self::assertMatchesRegularExpression(
            '/applyResponsePatches\(parsed,\s*captured\);\s*(?:try\s*\{\s*)?updateFormAggregate\(parsed,\s*captured\)/s',
            $code,
            'updateFormAggregate must run after applyResponsePatches inside the dispatch path.',
        );
    }

    #[Test]
    public function form_aggregate_reads_validation_state_from_response_debug(): void
    {
        $code = $this->jsCode();

        // The aggregate keys off debug.validation.{state,message} — the
        // shape the FieldComponent handler already returns. No new
        // wire shape is introduced.
        self::assertMatchesRegularExpression(
            '/var\s+validation\s*=\s*debug\.validation\b/',
            $code,
        );
        self::assertMatchesRegularExpression(
            '/state\s*!==\s*[\'"]valid[\'"]\s*&&\s*state\s*!==\s*[\'"]invalid[\'"]/',
            $code,
        );
    }

    #[Test]
    public function form_aggregate_resolves_form_root_by_form_aggregate_marker(): void
    {
        $code = $this->jsCode();

        // The walker must scope to [data-ui-form-aggregate="1"]
        // ancestors — never document-wide, never by an arbitrary
        // selector. Field DOM containment is the only membership.
        self::assertStringContainsString(
            'data-ui-form-aggregate',
            $code,
        );
        self::assertMatchesRegularExpression(
            '/\[\'\s*\+\s*FORM_AGGREGATE_ATTR\s*\+\s*\'="1"\]/',
            $code,
        );
        // The walker uses .closest from the field's parent so it never
        // selects the field's own root as its enclosing form.
        self::assertMatchesRegularExpression(
            '/fieldRoot\.parentNode\.closest\(/',
            $code,
        );
    }

    #[Test]
    public function form_aggregate_keys_per_field_state_by_safe_marker(): void
    {
        $code = $this->jsCode();

        // The walker must read data-ui-field-name (set by the field
        // template only when the name matches the safe identifier
        // pattern). The constant pin guards against ad-hoc renames.
        self::assertMatchesRegularExpression(
            '/FIELD_NAME_ATTR\s*=\s*[\'"]data-ui-field-name[\'"]/',
            $code,
        );
        // And re-validate the attribute value with the same identifier
        // regex the patch applier already uses — defense in depth.
        self::assertMatchesRegularExpression(
            '/IDENTIFIER_RE\.test\(fieldName\)/',
            $code,
        );
    }

    #[Test]
    public function form_aggregate_synthesises_safe_patches_only(): void
    {
        $code = $this->jsCode();

        // The two synthesized patches: setText on form-status target,
        // setAttribute ui-state on the form root. No setHtml. No
        // disabled. No third patch shape.
        self::assertMatchesRegularExpression(
            "/op:\s*'setText'/s",
            $code,
        );
        self::assertMatchesRegularExpression(
            "/name:\s*'form-status'/s",
            $code,
        );
        self::assertMatchesRegularExpression(
            "/op:\s*'setAttribute'/s",
            $code,
        );
        self::assertMatchesRegularExpression(
            "/attribute:\s*'ui-state'/s",
            $code,
        );
        // Hard ban: must never patch `disabled` on the submit button —
        // that attribute remains intentionally absent from the
        // allow-list in this slice.
        self::assertStringNotContainsString("attribute: 'disabled'", $code);
        self::assertStringNotContainsString('attribute: "disabled"', $code);
    }

    #[Test]
    public function form_aggregate_reuses_existing_safe_applier(): void
    {
        $code = $this->jsCode();

        // The aggregate patches must run through applyOnePatch — the
        // SAME validated DOM mutation engine the dispatch + SSE paths
        // already use. A second mutation engine would silently bypass
        // the patch contract.
        self::assertMatchesRegularExpression(
            '/applyAggregatePatches\([^)]*\)\s*\{(?:.|\n)*?applyOnePatch\(/s',
            $code,
        );

        // No new innerHTML / outerHTML / eval / Function in the new
        // aggregation block.
        self::assertStringNotContainsString('innerHTML', $code);
        self::assertStringNotContainsString('outerHTML', $code);
        self::assertStringNotContainsString('eval(', $code);
        self::assertStringNotContainsString('new Function(', $code);
    }

    #[Test]
    public function form_aggregate_never_evaluates_validation_rules_client_side(): void
    {
        $code = $this->jsCode();

        // No reference to the server-side rule classes/types on the
        // client. The runtime is observation-only.
        self::assertStringNotContainsString('UiFieldValidator', $code);
        self::assertStringNotContainsString('UiFieldRuleParser', $code);
        self::assertStringNotContainsString('UiFieldRuleRegistry', $code);
        self::assertStringNotContainsString('RequiredRule', $code);
        self::assertStringNotContainsString('MinLengthRule', $code);
        self::assertStringNotContainsString('MaxLengthRule', $code);

        // No regex / length checks of input values — the aggregator
        // only reads state strings returned by the server.
        self::assertStringNotContainsString('captured.value.length', $code);
        self::assertStringNotContainsString('payload.value.length', $code);
    }

    #[Test]
    public function form_aggregate_repeats_replace_previous_state_per_field(): void
    {
        $code = $this->jsCode();

        // Per-field state lives in a keyed map so repeated updates for
        // the same field overwrite — never duplicate — the entry.
        // The assignment is the contract.
        self::assertMatchesRegularExpression(
            '/bucket\.fields\[fieldKey\]\s*=\s*\{\s*state:\s*state\s*,\s*message:\s*message\s*\}/',
            $code,
        );
    }

    #[Test]
    public function form_aggregate_starts_empty(): void
    {
        $code = $this->jsCode();

        // The module-local state container initialises to `{}` — a
        // page load starts with no aggregate, the first dispatch
        // hydrates the first field bucket.
        self::assertMatchesRegularExpression(
            '/FORM_AGGREGATE_STATE\s*=\s*\{\s*\}/',
            $code,
        );
    }

    #[Test]
    public function form_aggregate_messages_are_deterministic_strings(): void
    {
        $code = $this->jsCode();

        // The three documented status messages must be present
        // verbatim — the playground (and future tests) pin them as
        // contract.
        self::assertStringContainsString('No fields validated yet.', $code);
        self::assertStringContainsString('1 field needs attention.', $code);
        self::assertStringContainsString(' fields need attention.', $code);
        self::assertStringContainsString('1 field validated — looks good.', $code);
        self::assertStringContainsString(' validated fields look good.', $code);
    }

    #[Test]
    public function form_aggregate_public_api_exposes_snapshot_and_reset(): void
    {
        $code = $this->jsCode();

        // window.SemitexaUi.forms.snapshot / reset are the only public
        // surfaces — kept narrow on purpose.
        self::assertMatchesRegularExpression('/forms:\s*\{/', $code);
        self::assertMatchesRegularExpression('/snapshot:\s*formAggregateSnapshot/', $code);
        self::assertMatchesRegularExpression('/reset:\s*formAggregateReset/', $code);
    }

    #[Test]
    public function form_aggregate_event_fires_after_update(): void
    {
        $code = $this->jsCode();

        self::assertStringContainsString("'semitexa:ui-form:aggregate'", $code);
        // Detail payload carries the summary so consumers can mirror the
        // aggregate without re-deriving it.
        self::assertMatchesRegularExpression(
            '/CustomEvent\(\s*[\'"]semitexa:ui-form:aggregate[\'"]\s*,\s*\{\s*detail:\s*\{/s',
            $code,
        );
    }

    #[Test]
    public function form_aggregate_uses_no_arbitrary_selectors(): void
    {
        $code = $this->jsCode();

        // The aggregator must never run document.querySelectorAll
        // with a caller-shaped selector. As of the cross-field
        // snapshot slice there are exactly THREE document.querySelector
        // callsites in the whole file:
        //
        //   1. patch applier's component-root resolver,
        //   2. form aggregate's field-root lookup,
        //   3. cross-field snapshot collector's field-root lookup.
        //
        // All three look up by `[data-ui-component-instance-id="<safe-id>"]`.
        // Any new callsite forces this test to be reviewed for
        // selector safety.
        self::assertSame(
            3,
            substr_count($code, 'document.querySelector('),
            'A new document.querySelector callsite was added — review for selector safety.',
        );
        // Every document.querySelector reads by the safe instance-id
        // attribute pattern. No raw class selectors, no tag selectors,
        // no attribute-presence selectors.
        $callsites = preg_match_all(
            '/document\.querySelector\(\s*\n?\s*[\'"]\[data-ui-component-instance-id="/',
            $code,
        );
        self::assertSame(3, $callsites);

        // document.querySelectorAll is allowed ONLY inside the form
        // snapshot collector, where the selector is the literal
        // `[data-ui-field-name]` attribute-presence query scoped to
        // the form root element (not document). The aggregator path
        // does not use it.
        $allCalls = substr_count($code, 'document.querySelectorAll');
        self::assertSame(0, $allCalls, 'document.querySelectorAll must not be called against document.');
    }
}
