<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The page's deferred request id rides only the first kiss stream.
 *
 * buildKissUrl() appended it to every URL while the deferred manifest was in
 * the DOM, so every later drain stream and the live stream a subscriber opens
 * carried a request that expires after 120 s. A stream whose bind token no
 * longer matches is refused before its queued answers are sent, so those
 * answers would have been lost.
 */
final class EventRuntimeKissUrlCarriesDeferredIdOnceTest extends TestCase
{
    private const JS = __DIR__ . '/../../../src/Application/Static/js/event-runtime.js';

    /** What a page open runs: maybeAutoOpenSse() and the drain-on-demand listeners it may arm. */
    private const PAGE_OPEN = ['buildKissUrl', 'hasDrainSseConnection', 'armDrainOnDemand', 'maybeAutoOpenSse'];

    #[Test]
    public function only_the_first_open_carries_the_deferred_request_id(): void
    {
        $urls = $this->inNode(<<<'JS'
            out({
                drain: buildKissUrl('sse_page', 'drain'),
                live: buildKissUrl('sse_page', 'live'),
                first: buildKissUrl('sse_page', 'drain', true),
            });
            JS);

        self::assertSame('/__semitexa_kiss?session_id=sse_page&mode=drain', $urls['drain']);
        self::assertSame('/__semitexa_kiss?session_id=sse_page&mode=live', $urls['live']);
        self::assertSame('/__semitexa_kiss?session_id=sse_page&mode=drain&deferred_request_id=dr_1', $urls['first']);
    }

    #[Test]
    public function only_the_initial_open_asks_for_it(): void
    {
        $source = (string) file_get_contents(self::JS);

        self::assertSame(1, preg_match_all('/buildKissUrl\([^)]*,\s*true\)/', $source), 'one call passes withDeferred');
        self::assertSame(1, preg_match('/url:\s*buildKissUrl\(sessionId, mode, true\)/', $source), 'and it is the initial open');
    }

    /**
     * The drain for a streamed answer does not open beside the page's first,
     * deferred drain. Its URL carries no deferred id, so attachSse no longer
     * merges the two, and a second drain would take the session over and
     * cancel the deferred slots the first is still rendering (review,
     * semitexa-platform-ui#106). The answer is counted, and the first drain's
     * close opens the one drain that carries it.
     */
    #[Test]
    public function a_streamed_answer_during_the_first_deferred_drain_waits_for_its_close(): void
    {
        $run = $this->inNode(<<<'JS'
            var first = '/__semitexa_kiss?session_id=sse_page&mode=drain&deferred_request_id=dr_1';
            ATTACHED_SSE_CONNECTIONS.push({ url: first });
            armDrainOnDemand();
            fire('semitexa:ui-event:dispatched', { response: { streamedPatchCount: 1 } });
            var duringFirst = attached.slice();
            ATTACHED_SSE_CONNECTIONS.length = 0;
            fire('semitexa:ui-sse:close', { url: first });
            out({ duringFirst: duringFirst, afterClose: attached });
            JS, ['buildKissUrl', 'hasDrainSseConnection', 'armDrainOnDemand']);

        self::assertSame([], $run['duringFirst'], 'no second drain beside the first');
        self::assertSame(['/__semitexa_kiss?session_id=sse_page&mode=drain'], $run['afterClose'], 'one drain, without the id, once the first closed');
    }

    /**
     * A page with deferred content opens its first stream in
     * maybeAutoOpenSse() and returned before arming drain-on-demand: once that
     * drain closed, a streamed answer stayed queued, as no listener opened
     * another drain.
     */
    #[Test]
    public function a_deferred_drain_page_arms_drain_on_demand(): void
    {
        $run = $this->inNode(<<<'JS'
            var first = '/__semitexa_kiss?session_id=sse_page&mode=drain&deferred_request_id=dr_1';
            var answer = { response: { streamedPatchCount: 1 } };
            maybeAutoOpenSse();
            var opened = attached.slice();
            fire('semitexa:ui-event:dispatched', answer);
            var duringFirst = attached.slice(1);
            closeStream(first);
            var afterFirstClose = attached.slice(1);
            closeStream(afterFirstClose[0]);
            var afterSecondClose = attached.slice(2);
            fire('semitexa:ui-event:dispatched', answer);
            out({
                opened: opened,
                cookie: document.cookie,
                duringFirst: duringFirst,
                afterFirstClose: afterFirstClose,
                afterSecondClose: afterSecondClose,
                afterAnswer: attached.slice(2),
            });
            JS, self::PAGE_OPEN);

        $drain = '/__semitexa_kiss?session_id=sse_page&mode=drain';
        self::assertSame([$drain . '&deferred_request_id=dr_1'], $run['opened'], 'the first stream, with the deferred id');
        self::assertStringStartsWith('semitexa_ssr_bind_dr_1=bt_1;', $run['cookie']);
        self::assertSame([], $run['duringFirst'], 'an answer during the first drain opens nothing');
        self::assertSame([$drain], $run['afterFirstClose'], 'its close opens one drain, without the id');
        self::assertSame([], $run['afterSecondClose'], 'a close with nothing outstanding opens nothing');
        self::assertSame([$drain], $run['afterAnswer'], 'an answer after the drains closed opens one');
    }

    /** The bug as a user met it: no answer during the first drain, one after it closed. */
    #[Test]
    public function an_answer_after_the_first_deferred_drain_closed_opens_a_drain(): void
    {
        $run = $this->inNode(<<<'JS'
            maybeAutoOpenSse();
            closeStream(attached[0]);
            var afterClose = attached.slice(1);
            fire('semitexa:ui-event:dispatched', { response: { streamedStateCount: 1 } });
            out({ afterClose: afterClose, afterAnswer: attached.slice(1) });
            JS, self::PAGE_OPEN);

        self::assertSame([], $run['afterClose'], 'nothing was outstanding');
        self::assertSame(['/__semitexa_kiss?session_id=sse_page&mode=drain'], $run['afterAnswer']);
    }

    /** A live page holds its one stream open: it arms nothing, so a dispatched answer opens nothing. */
    #[Test]
    public function a_live_deferred_page_does_not_arm_drain_on_demand(): void
    {
        $run = $this->inNode(<<<'JS'
            pageMode = 'live';
            maybeAutoOpenSse();
            fire('semitexa:ui-event:dispatched', { response: { streamedPatchCount: 1 } });
            out({ attached: attached, armed: drainOnDemandArmed, listeners: Object.keys(listeners) });
            JS, self::PAGE_OPEN);

        self::assertSame(['/__semitexa_kiss?session_id=sse_page&mode=live&deferred_request_id=dr_1'], $run['attached']);
        self::assertFalse($run['armed']);
        self::assertSame([], $run['listeners'], 'no drain-on-demand listener on a live page');
    }

    /**
     * @param list<string> $names
     * @return array<string, mixed>
     */
    private function inNode(string $scenario, array $names = ['buildKissUrl']): array
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        // Not skipped without node: the image ships it, and a skip would pass a regression.
        self::assertNotSame('', $node, 'node runs buildKissUrl; the runtime image ships it.');

        $source = (string) file_get_contents(self::JS);
        $functions = '';
        foreach ($names as $name) {
            self::assertSame(1, preg_match('/^    function ' . $name . '\(.*?^    \}$/ms', $source, $match), $name . '() not found');
            $functions .= ($match[0] ?? '') . "\n";
        }

        $script = <<<'JS'
            var SSE_TRANSPORT_MODE_DRAIN = 'drain';
            var SSE_TRANSPORT_MODE_LIVE = 'live';
            var ATTACHED_SSE_CONNECTIONS = [];
            var drainOnDemandArmed = false;
            var drainOnDemandOpened = false;
            var drainStreamedWhileOpen = 0;
            var attached = [];
            var listeners = {};
            var pageMode = 'drain';
            var pageStreamSession = null;
            var window = {};
            function EventSource() {}
            var document = { cookie: '', addEventListener: function (type, fn) { (listeners[type] = listeners[type] || []).push(fn); } };
            function fire(type, detail) { (listeners[type] || []).forEach(function (fn) { fn({ detail: detail }); }); }
            function attachSse(options) { attached.push(options.url); ATTACHED_SSE_CONNECTIONS.push({ url: options.url }); }
            // As closeForGood: the connection leaves the list, then the close is emitted.
            function closeStream(url) {
                ATTACHED_SSE_CONNECTIONS = ATTACHED_SSE_CONNECTIONS.filter(function (c) { return c.url !== url; });
                fire('semitexa:ui-sse:close', { url: url });
            }
            function readPageSseSessionId() { return 'sse_page'; }
            function readPageTransportMode() { return pageMode; }
            function readDeferredManifest() { return { requestId: 'dr_1', bindToken: 'bt_1' }; }
            function out(value) { process.stdout.write(JSON.stringify(value)); }

            JS . $functions . $scenario;

        $file = (string) tempnam(sys_get_temp_dir(), 'kissurl');
        file_put_contents($file, $script);
        try {
            exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1', $lines, $exit);
        } finally {
            @unlink($file);
        }
        $output = implode("\n", $lines);
        self::assertSame(0, $exit, $output);

        $decoded = json_decode($output, true);
        self::assertIsArray($decoded, $output);

        $result = [];
        foreach ($decoded as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }
}
