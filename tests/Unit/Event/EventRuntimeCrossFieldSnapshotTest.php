<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Asset-level invariants for the cross-field form-value snapshot
 * collector inside event-runtime.js. The collector runs in the
 * browser; PHP can only pin structural rules about what the file
 * does and does NOT contain — the security perimeter for what
 * leaves the page in `payload.form.values`.
 */
final class EventRuntimeCrossFieldSnapshotTest extends TestCase
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
    public function collector_runs_before_wire_body_encoding(): void
    {
        $code = $this->jsCode();
        // The transport must seed `payloadObj` with the captured value
        // and then call collectFormValuesSnapshot(captured) BEFORE
        // building the wire body. Order is the contract — if the
        // collector ran later the snapshot would never reach the
        // server.
        self::assertMatchesRegularExpression(
            '/var\s+payloadObj\s*=\s*\{\s*value:\s*captured\.value\s*\};\s*'
                . 'var\s+formSnapshot\s*=\s*collectFormValuesSnapshot\(captured\);/s',
            $code,
        );
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*formSnapshot\s*!==\s*null\s*\)\s*\{\s*'
                . 'payloadObj\.form\s*=\s*\{\s*values:\s*formSnapshot\s*\};\s*\}/s',
            $code,
        );
    }

    #[Test]
    public function collector_scopes_to_form_aggregate_root_only(): void
    {
        $code = $this->jsCode();
        // The collector resolves the enclosing form-aggregate root in
        // one of two ways:
        //   - the captured instance IS itself a form root (submit
        //     case): use it directly.
        //   - otherwise walk up from its parent through .closest().
        // Either path must use the exact safe attribute pattern
        // `[data-ui-form-aggregate="1"][data-ui-component-instance-id]`.
        self::assertMatchesRegularExpression(
            '/instanceEl\.matches\(\s*\n?\s*'
                . '[\'"]\[data-ui-form-aggregate="1"\]\[data-ui-component-instance-id\][\'"]\s*\)/s',
            $code,
            'Collector must check whether the captured instance IS the form root.',
        );
        self::assertMatchesRegularExpression(
            '/instanceEl\.parentNode\.closest\(\s*\n?\s*'
                . '[\'"]\[data-ui-form-aggregate="1"\]\[data-ui-component-instance-id\][\'"]\s*\)/s',
            $code,
            'Collector must walk up from parentNode when the captured instance is not itself a form root.',
        );
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*!formRoot\s*\)\s*\{\s*return\s+null\s*;\s*\}/s',
            $code,
        );
    }

    #[Test]
    public function collector_enumerates_only_safe_field_name_descendants(): void
    {
        $code = $this->jsCode();
        // Field selection is by attribute presence — `[data-ui-field-name]`.
        // The querySelectorAll is scoped to formRoot (NOT document)
        // so a field in an unrelated form on the same page never
        // leaks into this snapshot.
        self::assertMatchesRegularExpression(
            '/formRoot\.querySelectorAll\(\s*[\'"]\[data-ui-field-name\][\'"]\s*\)/s',
            $code,
        );
        // Even after the template's filter, the collector re-validates
        // the field-name attribute against the safe identifier
        // pattern. Defence in depth — a hand-injected attribute does
        // NOT smuggle anything onto the wire.
        self::assertMatchesRegularExpression(
            '/var\s+FIELD_NAME_SAFE_RE\s*=\s*\/\^\[A-Za-z_\]\[A-Za-z0-9_-\]\*\$\//',
            $code,
        );
        self::assertMatchesRegularExpression(
            '/FIELD_NAME_SAFE_RE\.test\(name\)/',
            $code,
        );
    }

    #[Test]
    public function collector_reads_value_only_from_data_ui_part_input(): void
    {
        $code = $this->jsCode();
        // Values come from the `[data-ui-part="input"]` element's
        // `.value` property — the exact surface the existing capture
        // path already reads. No `innerText`, no `dataset` mining,
        // no traversal beyond the immediate input part.
        self::assertMatchesRegularExpression(
            '/fieldEl\.querySelector\(\s*[\'"]\[data-ui-part="input"\][\'"]\s*\)/s',
            $code,
        );
        self::assertMatchesRegularExpression(
            "/'value'\s+in\s+inputEl/",
            $code,
        );
    }

    #[Test]
    public function collector_coerces_scalar_only_values(): void
    {
        $code = $this->jsCode();
        // Defensive non-scalar guard: even if a future input surface
        // returns an object via `.value`, the collector drops it
        // rather than smuggling it onto the wire. The check pins
        // the explicit allow-list of value types.
        self::assertMatchesRegularExpression(
            "/typeof\\s+rawValue\\s*!==\\s*'string'\\s*&&\\s*"
                . "typeof\\s+rawValue\\s*!==\\s*'number'\\s*&&\\s*"
                . "typeof\\s+rawValue\\s*!==\\s*'boolean'/s",
            $code,
        );
    }

    #[Test]
    public function collector_never_emits_rules_or_routing_keys(): void
    {
        $code = $this->jsCode();
        // The snapshot is a map of `<fieldName> → <scalar>`. The
        // collector must never push a `rules` / `cfg` / `r` /
        // `component` / `instance` / `part` / `event` / `handler` /
        // `method` / `class` / `endpoint` key into it.
        foreach (
            [
                'snapshot.rules',
                'snapshot.cfg',
                'snapshot.r',
                'snapshot.component',
                'snapshot.instance',
                'snapshot.part',
                'snapshot.event',
                'snapshot.handler',
                'snapshot.method',
                'snapshot.class',
                'snapshot.endpoint',
            ] as $forbidden
        ) {
            self::assertStringNotContainsString(
                $forbidden,
                $code,
                'Snapshot collector must not introduce a "' . $forbidden . '" key.',
            );
        }
    }

    #[Test]
    public function collector_does_not_evaluate_rules_or_use_unsafe_apis(): void
    {
        $code = $this->jsCode();
        // No references to validator/rule classes — validation
        // stays server-side. (The strings would only appear if the
        // collector tried to evaluate something.)
        self::assertStringNotContainsString('UiFieldValidator', $code);
        self::assertStringNotContainsString('UiFieldRuleParser', $code);
        // No string-execution / DOM-write APIs added by this slice.
        self::assertStringNotContainsString('innerHTML', $code);
        self::assertStringNotContainsString('outerHTML', $code);
        self::assertStringNotContainsString('eval(', $code);
        self::assertStringNotContainsString('new Function(', $code);
    }

    #[Test]
    public function collector_yields_null_outside_form_root(): void
    {
        $code = $this->jsCode();
        // The contract: standalone fields (not inside a
        // FormComponent) produce `null` from the collector so the
        // wire body keeps its previous shape (no `payload.form`
        // key). The if-not-null guard upstream is the inverse pin.
        self::assertMatchesRegularExpression(
            '/function\s+collectFormValuesSnapshot\(captured\)[^{]*\{[^}]*?return\s+null\s*;/s',
            $code,
        );
    }
}
