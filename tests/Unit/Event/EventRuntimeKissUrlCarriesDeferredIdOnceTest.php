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
            var document = { addEventListener: function (type, fn) { (listeners[type] = listeners[type] || []).push(fn); } };
            function fire(type, detail) { (listeners[type] || []).forEach(function (fn) { fn({ detail: detail }); }); }
            function attachSse(options) { attached.push(options.url); }
            function readPageSseSessionId() { return 'sse_page'; }
            function readPageTransportMode() { return 'drain'; }
            function readDeferredManifest() { return { requestId: 'dr_1' }; }
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
