<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Static invariants for the idle-disconnect self-heal added to
 * event-runtime.js: on tab refocus the runtime revives any non-OPEN
 * canonical SSE connection, and on a re-`connected` frame it emits a
 * `semitexa:ui-sse:reconnected` signal so page consumers (the grid) can
 * re-sync state lost while the socket was down. The runtime executes in
 * the browser; PHP can only pin the structural rules.
 */
final class EventRuntimeSseReviveReconnectTest extends TestCase
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
    public function registers_a_visibility_revive_listener(): void
    {
        $code = $this->jsCode();
        self::assertMatchesRegularExpression(
            "/addEventListener\(\s*'visibilitychange'/",
            $code,
            'event-runtime.js must revive the stream on tab refocus via a visibilitychange listener.',
        );
        self::assertMatchesRegularExpression(
            "/visibilityState\s*===\s*'visible'/",
            $code,
            'the revive must fire only when the tab becomes visible.',
        );
    }

    #[Test]
    public function revive_skips_open_connections_and_reopens_via_attach_sse(): void
    {
        $code = $this->jsCode();
        self::assertMatchesRegularExpression(
            '/function\s+reviveSseConnections\s*\(/',
            $code,
            'event-runtime.js must define reviveSseConnections().',
        );
        // Healthy (OPEN) connections MUST be skipped so the revive never
        // churns a perfectly good live stream.
        self::assertMatchesRegularExpression(
            '/readyState\s*===\s*OPEN/',
            $code,
            'reviveSseConnections must skip connections whose readyState is already OPEN.',
        );
        // A revived connection re-opens through the canonical attach path.
        self::assertMatchesRegularExpression(
            '/attachSse\(\s*\{\s*url:\s*entry\.url\s*\}\s*\)/',
            $code,
            'reviveSseConnections must re-open via attachSse({ url: entry.url }).',
        );
    }

    #[Test]
    public function emits_reconnected_signal_only_after_a_real_reconnect(): void
    {
        $code = $this->jsCode();
        self::assertStringContainsString(
            "emitTransportEvent('semitexa:ui-sse:reconnected'",
            $code,
            'event-runtime.js must emit semitexa:ui-sse:reconnected when a stream re-establishes.',
        );
        // Gated only so the INITIAL connect does not emit (prevTs !== 0). It
        // used to skip a reconnect within two seconds of the previous connect
        // too, but the server drops a connection's feed subscriptions with it,
        // so a quick flap left every feed unsubscribed (tk-ls-replay).
        self::assertMatchesRegularExpression('/if \(prevTs !== 0\) \{/', $code, 'every later connect is a reconnect.');
        self::assertStringNotContainsString('SSE_RECONNECT_MIN_GAP_MS', $code);
    }
}
