<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Asset;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Static safety pins for grid-runtime-v2.js — the ONLY grid runtime since the
 * One Way Phase 6 sweep deleted grid-runtime.js (v1). These are the
 * non-negotiable invariants the deleted v1 static-assert suite enforced,
 * re-pointed at v2: the runtime renders server data into the DOM, so it must
 * never open an HTML/JS injection channel, and it must stay wired as a global
 * deferred asset so the `[data-ui-grid-v2]` shells it boots keep working.
 */
final class GridRuntimeV2StaticAssertTest extends TestCase
{
    private const RUNTIME_PATH =
        __DIR__ . '/../../../src/Application/Static/js/grid-runtime-v2.js';

    private const ASSETS_JSON_PATH =
        __DIR__ . '/../../../src/Application/Static/assets.json';

    private static function runtimeSource(): string
    {
        $source = file_get_contents(self::RUNTIME_PATH);
        self::assertIsString($source);

        return $source;
    }

    #[Test]
    public function the_runtime_exists_at_the_documented_path(): void
    {
        self::assertFileExists(
            self::RUNTIME_PATH,
            'grid-runtime-v2.js must exist at the documented path.',
        );
    }

    #[Test]
    public function the_runtime_is_a_global_deferred_body_asset(): void
    {
        $manifest = json_decode((string) file_get_contents(self::ASSETS_JSON_PATH), true);
        self::assertIsArray($manifest);

        $override = $manifest['overrides']['js/grid-runtime-v2.js'] ?? null;
        self::assertIsArray(
            $override,
            'grid-runtime-v2.js must be declared as an override in assets.json.',
        );
        self::assertSame('global', $override['scope']);
        self::assertSame('body', $override['position']);
        self::assertTrue($override['attributes']['defer'] ?? false);
    }

    #[Test]
    public function the_legacy_v1_runtime_stays_deleted(): void
    {
        self::assertFileDoesNotExist(
            \dirname(self::RUNTIME_PATH) . '/grid-runtime.js',
            'grid-runtime.js (v1) was deleted in the One Way Phase 6 sweep and must not come back.',
        );

        $manifest = json_decode((string) file_get_contents(self::ASSETS_JSON_PATH), true);
        self::assertIsArray($manifest);
        self::assertArrayNotHasKey(
            'js/grid-runtime.js',
            $manifest['overrides'] ?? [],
            'assets.json must not re-declare the deleted v1 runtime.',
        );
    }

    #[Test]
    public function the_runtime_never_assigns_inner_html(): void
    {
        self::assertStringNotContainsString(
            'innerHTML',
            self::runtimeSource(),
            'grid-runtime-v2.js must never assign innerHTML — use textContent + createElement instead.',
        );
    }

    #[Test]
    public function the_runtime_never_calls_eval_or_equivalents(): void
    {
        $source = self::runtimeSource();

        self::assertDoesNotMatchRegularExpression(
            '/\beval\s*\(/',
            $source,
            'grid-runtime-v2.js must not call eval().',
        );
        self::assertDoesNotMatchRegularExpression(
            '/\bnew\s+Function\s*\(/',
            $source,
            'grid-runtime-v2.js must not use the Function constructor.',
        );
        self::assertStringNotContainsString(
            'document.write',
            $source,
            'grid-runtime-v2.js must not use document.write().',
        );
    }

    #[Test]
    public function the_runtime_renders_via_text_content_and_create_element(): void
    {
        $source = self::runtimeSource();

        self::assertStringContainsString(
            '.textContent',
            $source,
            'grid-runtime-v2.js must render text via textContent.',
        );
        self::assertStringContainsString(
            'createElement(',
            $source,
            'grid-runtime-v2.js must build DOM nodes via createElement.',
        );
    }

    #[Test]
    public function the_runtime_sends_the_csrf_header_on_mutations(): void
    {
        self::assertStringContainsString(
            'X-CSRF-Token',
            self::runtimeSource(),
            'grid-runtime-v2.js must send the X-CSRF-Token header on non-GET requests.',
        );
    }

    #[Test]
    public function link_hrefs_are_guarded_root_relative_with_no_backslash_bypass(): void
    {
        // The ONLY URL-from-data sink. `/\evil.com` normalises to
        // `//evil.com` in browsers, so the guard must reject `\` too.
        self::assertStringContainsString(
            '/^\\/(?![\\/\\\\])/.test(href)',
            self::runtimeSource(),
            'interpolateHref must keep the root-relative guard that also rejects the /\\ backslash bypass.',
        );
    }

    #[Test]
    public function action_routes_are_guarded_before_carrying_the_csrf_token(): void
    {
        $source = self::runtimeSource();

        self::assertStringContainsString(
            '/^\\/(?![\\/\\\\])/.test(route)',
            $source,
            'invokeAction must reject non-root-relative routes before fetching with the CSRF header.',
        );
        self::assertStringContainsString(
            "method !== 'POST' && method !== 'PUT' && method !== 'PATCH' && method !== 'DELETE'",
            $source,
            'invokeAction must allow only mutating verbs.',
        );
    }

    #[Test]
    public function a_failed_reconnect_does_not_permanently_degrade_a_proven_stream(): void
    {
        $source = self::runtimeSource();

        self::assertStringContainsString(
            'everStreamed',
            $source,
            'the runtime must track whether ANY connection ever streamed a frame.',
        );
        self::assertStringContainsString(
            '!state.gotFrame && !state.everStreamed',
            $source,
            'permanent degrade-to-pull must require that NO connection ever delivered a frame — a dropped-then-failed reconnect stays on the backoff path.',
        );
    }

    #[Test]
    public function the_runtime_boots_from_the_v2_shell_marker(): void
    {
        self::assertStringContainsString(
            'data-ui-grid-v2',
            self::runtimeSource(),
            'grid-runtime-v2.js must boot from the [data-ui-grid-v2] shell marker.',
        );
    }
}
