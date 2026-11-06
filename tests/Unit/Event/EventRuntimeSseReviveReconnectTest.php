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

    #[Test]
    public function reopens_a_live_stream_the_server_closed_at_its_age_cap(): void
    {
        $code = $this->jsCode();
        // The server ends every held-open stream at SSE_MAX_CONNECTION_AGE_SECONDS
        // with `event: close, reason: max_age`. Treated as a teardown, a live
        // page stopped receiving rows ten minutes after it opened.
        self::assertMatchesRegularExpression(
            "/parsed\\.reason === 'max_age'[^\\n]*SSE_TRANSPORT_MODE_DRAIN\\) === -1\\) \\{\\s*attachSse\\(\\{ url: url \\}\\);/",
            $code,
            'the close handler must reopen a non-drain stream closed for max_age.',
        );
    }

    #[Test]
    public function a_reconnect_re_arms_subscriptions_once(): void
    {
        $code = $this->jsCode();
        // The server used to reap a dead connection's session on its next
        // failed heartbeat, taking the subscriptions the reconnect had just
        // re-armed, so the runtime re-armed them a second time 30 s later. The
        // reap now spares what the new connection subscribed (mw-2; pinned by
        // ssr's ConnectCoordinatorTest and kiss-reconnect-keeps-subscriptions),
        // so one re-arm is the whole story.
        // verify:accept-test-change the second, delayed resubscribe is gone with the server reap that made it necessary (mw-2)
        self::assertStringNotContainsString('SSE_RESUBSCRIBE_SETTLE_MS', $code);
        self::assertStringNotContainsString('resubscribeSettleTimer', $code);
        self::assertSame(1, substr_count($code, 'resubscribeAll();'), 'the reconnected handler re-arms once');
    }

    #[Test]
    public function a_shell_swap_hands_the_stream_to_the_page_that_arrived(): void
    {
        $code = $this->jsCode();
        // Presence only; the browser behaviour has no test in this package.
        self::assertStringContainsString("addEventListener('semitexa:navigation:swapped', followPageSwap)", $code);
        self::assertMatchesRegularExpression(
            '/function followPageSwap\(\) \{\s*closePageStreams\(\);.*?maybeAutoOpenSse\(\);.*?setTimeout\(rehomeSubscriptions, 0\);/s',
            $code,
            'close the old page, open the new one, then move what the swap left standing.',
        );
    }

    #[Test]
    public function a_page_with_no_session_pauses_a_kept_feed_instead_of_dropping_it(): void
    {
        $code = $this->jsCode();
        preg_match('/function pauseSubscriptions\(\) \{.*?\n    \}/s', $code, $pause);
        self::assertNotEmpty($pause);
        self::assertStringContainsString('s.onUnavailable(true)', $pause[0]);
        self::assertStringNotContainsString('delete SSE_SUBSCRIPTIONS', $pause[0], 'the next live page moves it back.');
        preg_match('/function rehomeSubscriptions\(\) \{.*?\n    \}/s', $code, $rehome);
        self::assertNotEmpty($rehome);
        self::assertStringContainsString('s.onResumed()', $rehome[0]);
    }

    #[Test]
    public function a_finished_drain_stream_closes_on_the_frame_that_says_so(): void
    {
        // Measured in Chrome: `done` with reconnect:false and no `close` event after
        // it left the stream to the browser, which reopened it every 3 s.
        self::assertMatchesRegularExpression(
            "/if \\(parsed\\.reconnect === false && url\\.indexOf\\('mode=' \\+ SSE_TRANSPORT_MODE_DRAIN\\) !== -1\\) \\{\\s*closeForGood\\(parsed\\);\\s*\\}/",
            $this->jsCode(),
        );
    }

    #[Test]
    public function a_live_stream_of_another_session_does_not_count(): void
    {
        // A feed moved to a session would otherwise ride nothing.
        preg_match('/function hasLiveSseConnection\(sessionId\) \{.*?\n    \}/s', $this->jsCode(), $body);
        self::assertNotEmpty($body);
        self::assertStringContainsString("u.indexOf(addressed) !== -1", $body[0]);
    }

    #[Test]
    public function a_move_without_movebefore_owes_the_change_it_resets(): void
    {
        // Presence only; measured in WebKit, where the blur after the move fired none.
        $code = $this->jsCode();
        self::assertMatchesRegularExpression('/if \(held\) oweChange\(held\);\s*morphIsMoving = true;/', $code);
        preg_match('/function oweChange\(control\) \{.*?\n    \}/s', $code, $body);
        self::assertNotEmpty($body);
        self::assertStringContainsString("control.addEventListener('change', settle, true);", $body[0], 'the browser may pay it first.');
        self::assertStringContainsString("if (ev.type === 'blur' && control.value !== started)", $body[0]);
    }

    #[Test]
    public function a_paused_grid_keeps_its_channel_and_its_view(): void
    {
        // Presence only; measured in Chrome: perPage set on a page with no session
        // came back in the subscribe of the next live page.
        $dir = \dirname(__DIR__, 3) . '/src/Application/Static/js';
        $grid = (string) file_get_contents($dir . '/grid-runtime-v2.js');
        self::assertMatchesRegularExpression('/onPull: function \(paused\) \{.*?if \(!paused\) \{.*?channel = null;/s', $grid);
        self::assertStringContainsString('if (channel) channel.view(currentViewParams());', $grid);
        self::assertStringContainsString('opts.onPull(paused === true)', (string) file_get_contents($dir . '/ui-core.js'));
    }

    #[Test]
    public function a_script_a_region_carries_runs_with_the_region_not_before_it(): void
    {
        // Presence only; measured in Chrome: 2 runs before, the first on no markup, then 1 with it.
        $nav = (string) file_get_contents(\dirname(__DIR__, 3) . '/src/Application/Static/js' . '/navigation.js');
        // verify:accept-test-change the split into before and after is gone, one filter replaced it
        self::assertStringContainsString('ensureAssets(assetsOutsideRegions(payload))', $nav);
        self::assertStringNotContainsString('loadedForThisSwap', $nav);
    }

    #[Test]
    public function a_navigation_overtaken_while_its_scripts_load_stays_quiet(): void
    {
        $nav = (string) file_get_contents(\dirname(__DIR__, 3) . '/src/Application/Static/js' . '/navigation.js');
        self::assertMatchesRegularExpression('/activateScripts\(document\)\.then\(\(ok\) => \{\s*if \(!ok\) \{.*?if \(token === navigationToken\) fallback\(/s', $nav);
    }

    #[Test]
    public function frames_wait_for_a_runtime_that_loads_after_them(): void
    {
        // Presence only; measured in Chrome with semitexa-twig.js 1.5 s late: a slot
        // filled at 7.6 s through its fallback, and at 1.6 s with the frames kept.
        $code = $this->jsCode();
        self::assertStringContainsString('takeEarlyFrames: takeEarlyFrames,', $code);
        preg_match('/function emitFrame\(detail\) \{.*?\n    \}/s', $code, $body);
        self::assertNotEmpty($body);
        self::assertStringContainsString('frameBacklog.length < FRAME_BACKLOG_LIMIT', $body[0], 'a page nobody takes them from stays bounded.');
        self::assertSame(0, substr_count($code, "emitTransportEvent('semitexa:ui-sse:frame'") - 1, 'every frame goes through emitFrame.');
    }

    #[Test]
    public function closing_a_page_stream_does_not_announce_a_server_close(): void
    {
        // `semitexa:ui-sse:close` reopens a drain stream.
        preg_match('/function closePageStreams\(\) \{.*?\n    \}/s', $this->jsCode(), $body);
        self::assertNotEmpty($body);
        self::assertStringNotContainsString('emitTransportEvent', $body[0]);
        self::assertStringNotContainsString('closeForGood', $body[0]);
    }

    #[Test]
    public function drain_on_demand_addresses_the_page_it_is_on(): void
    {
        $code = $this->jsCode();
        self::assertStringContainsString('buildKissUrl(readPageSseSessionId(), SSE_TRANSPORT_MODE_DRAIN)', $code);
        self::assertStringContainsString(
            'if (readPageSseSessionId() === null || readPageTransportMode() === SSE_TRANSPORT_MODE_LIVE) {',
            $code,
            'the listener outlives the page that armed it.',
        );
    }

    #[Test]
    public function a_subscription_remembers_the_session_it_was_made_on(): void
    {
        $code = $this->jsCode();
        // A feed moved to a new session must not unsubscribe from the old one.
        self::assertStringContainsString("postStreamControl('view', s.feed, s.params, s.session, subscriptionId)", $code);
        self::assertStringContainsString("postStreamControl('unsubscribe', feedRef.feed, null, s.session, subscriptionId)", $code);
    }

    #[Test]
    public function navigation_replaces_the_pages_metas_and_says_so_inside_the_swap(): void
    {
        $source = (string) file_get_contents(self::PACKAGE_ROOT . '/src/Application/Static/js/navigation.js');
        self::assertMatchesRegularExpression(
            "/applyDeferredManifest\(payload\.deferredManifest\);\s*applyPageMeta\(payload\.pageMeta\);.*?new CustomEvent\('semitexa:navigation:swapped'.*?return firstRegion;/s",
            $source,
            'the signal comes after everything a stream is built from is in place, and before commit() returns.',
        );
    }
}
