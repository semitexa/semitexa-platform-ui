<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Asset;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Static safety pins for ui-core.js — the shared client core every
 * platform-ui runtime depends on for HTML-escaping and CSRF plumbing.
 * The core MUST load before every other runtime (assets.json priority),
 * MUST be the single place that knows the CSRF cookie/header contract,
 * and — like every runtime — must never open an HTML/JS injection channel.
 */
final class UiCoreAssetTest extends TestCase
{
    private const CORE_PATH =
        __DIR__ . '/../../../src/Application/Static/js/ui-core.js';

    private const ASSETS_JSON_PATH =
        __DIR__ . '/../../../src/Application/Static/assets.json';

    private static function coreSource(): string
    {
        $source = file_get_contents(self::CORE_PATH);
        self::assertIsString($source);

        return $source;
    }

    /** @return array<string, array<string, mixed>> */
    private static function overrides(): array
    {
        $manifest = json_decode((string) file_get_contents(self::ASSETS_JSON_PATH), true);
        self::assertIsArray($manifest);
        self::assertIsArray($manifest['overrides'] ?? null);

        return $manifest['overrides'];
    }

    #[Test]
    public function the_core_exists_at_the_documented_path(): void
    {
        self::assertFileExists(
            self::CORE_PATH,
            'ui-core.js must exist at the documented path.',
        );
    }

    #[Test]
    public function the_core_is_a_global_deferred_body_asset(): void
    {
        $override = self::overrides()['js/ui-core.js'] ?? null;
        self::assertIsArray(
            $override,
            'ui-core.js must be declared as an override in assets.json.',
        );
        self::assertSame('global', $override['scope']);
        self::assertSame('body', $override['position']);
        self::assertTrue($override['attributes']['defer'] ?? false);
    }

    #[Test]
    public function the_core_loads_before_every_other_js_runtime(): void
    {
        $overrides = self::overrides();
        $corePriority = $overrides['js/ui-core.js']['priority'] ?? null;
        self::assertIsInt($corePriority);

        foreach ($overrides as $asset => $override) {
            if ($asset === 'js/ui-core.js' || !str_starts_with($asset, 'js/')) {
                continue;
            }
            self::assertIsInt($override['priority'] ?? null, "{$asset} must declare a priority.");
            self::assertLessThan(
                $override['priority'],
                $corePriority,
                "ui-core.js must load before {$asset} — every runtime depends on SemitexaUi.core.",
            );
        }
    }

    #[Test]
    public function the_core_owns_the_csrf_contract(): void
    {
        $source = self::coreSource();

        self::assertStringContainsString(
            'XSRF-TOKEN',
            $source,
            'ui-core.js must read the double-submit XSRF-TOKEN cookie.',
        );
        self::assertStringContainsString(
            'X-CSRF-Token',
            $source,
            'ui-core.js must echo the token back as the X-CSRF-Token header on unsafe methods.',
        );
    }

    #[Test]
    public function the_core_exports_the_shared_helpers(): void
    {
        $source = self::coreSource();

        foreach (['esc:', 'readCsrfToken:', 'withCsrf:', 'fetchJson:', 'openFeedChannel:', 'onReady:'] as $key) {
            self::assertStringContainsString(
                $key,
                $source,
                "ui-core.js must export {$key} on SemitexaUi.core.",
            );
        }
    }

    #[Test]
    public function the_core_extends_the_namespace_instead_of_replacing_it(): void
    {
        self::assertStringContainsString(
            'window.SemitexaUi = window.SemitexaUi || {}',
            self::coreSource(),
            'ui-core.js must extend the shared SemitexaUi namespace, never replace it.',
        );
    }

    #[Test]
    public function the_core_never_opens_an_injection_channel(): void
    {
        $source = self::coreSource();

        self::assertStringNotContainsString('innerHTML', $source);
        self::assertDoesNotMatchRegularExpression('/\beval\s*\(/', $source);
        self::assertDoesNotMatchRegularExpression('/\bnew\s+Function\s*\(/', $source);
        self::assertStringNotContainsString('document.write', $source);
    }

    #[Test]
    public function feed_consumers_never_open_their_own_event_source(): void
    {
        // The feed transport (shared KISS subscribe → dedicated EventSource
        // degrade with backoff) lives ONLY in core.openFeedChannel. The two
        // feed consumers must never grow a private copy back. (event-runtime
        // owns the shared KISS connection itself and calendar-runtime's
        // reopen-per-range stream predates the canonical envelope — both are
        // pinned by their own tests.)
        $jsDir = \dirname(self::CORE_PATH);
        foreach (['grid-runtime-v2.js', 'form-collab-runtime.js'] as $file) {
            $source = (string) file_get_contents($jsDir . '/' . $file);
            self::assertStringNotContainsString(
                'new EventSource(',
                $source,
                "{$file} must open live feeds via SemitexaUi.core.openFeedChannel only.",
            );
        }

        self::assertSame(
            1,
            substr_count(self::coreSource(), 'new EventSource('),
            'ui-core.js must construct the dedicated EventSource in exactly one place (openFeedChannel).',
        );
    }

    #[Test]
    public function every_runtime_delegates_escaping_to_the_core(): void
    {
        // No runtime may keep a private HTML-escape copy — drift between
        // copies is how the pre-core duplication started.
        $jsDir = \dirname(self::CORE_PATH);
        foreach ((array) glob($jsDir . '/*.js') as $file) {
            if (basename((string) $file) === 'ui-core.js') {
                continue;
            }
            $source = (string) file_get_contents((string) $file);
            self::assertDoesNotMatchRegularExpression(
                '/function\s+esc(?:Html|apeHtml)?\s*\(/',
                $source,
                basename((string) $file) . ' must use SemitexaUi.core.esc instead of a private escape helper.',
            );
        }
    }
}
