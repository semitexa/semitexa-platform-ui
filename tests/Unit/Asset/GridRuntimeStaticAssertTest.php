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
    public function runtime_listens_for_sse_patch_applied_event(): void
    {
        $source = $this->loadRuntime();
        self::assertStringContainsString(
            "semitexa:ui-sse:patch-applied",
            $source,
            'grid-runtime.js must listen for the framework SSE patch-applied event.',
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
}
