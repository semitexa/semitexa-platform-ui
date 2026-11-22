<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A feed kept across a shell navigation moves to the new page's session under
 * a NEW subscription id.
 *
 * The server ties a subscription id to the session that subscribed it and
 * refuses the same id from another session; the old page's subscription stays
 * there until its stream is seen closed. Re-subscribing the old id from the new
 * page's session was therefore refused (`subscribe_denied`), and the feed
 * stayed dead: the client has no retry.
 *
 * The subscription functions are run as written, in node, over stubs of the
 * few runtime functions they call.
 */
final class EventRuntimeRehomeMintsSubscriptionIdTest extends TestCase
{
    private const JS = __DIR__ . '/../../../src/Application/Static/js/event-runtime.js';

    #[Test]
    public function a_rehomed_feed_subscribes_under_a_new_id_and_its_handle_follows(): void
    {
        $run = $this->inNode(<<<'JS'
            var frames = [];
            var handle = sseSubscribe({ feed: 'leads.feed' }, { page: 1 }, function (f) { frames.push(f); });
            var first = handle.subscriptionId;

            session = 'sse_page_two';
            rehomeSubscriptions();
            var second = handle.subscriptionId;
            SSE_SUBSCRIPTIONS[second].onFrame('row');

            handle.view({ page: 2 });
            handle.unsubscribe();

            out({ first: first, second: second, posts: posts, frames: frames, left: Object.keys(SSE_SUBSCRIPTIONS), oldKept: first in SSE_SUBSCRIPTIONS });
            JS);

        self::assertNotSame($run['first'], $run['second'], 'the new session never presents the old session\'s id');
        self::assertSame(
            [
                ['subscribe', 'leads.feed', 'sse_page_one', $run['first']],
                ['subscribe', 'leads.feed', 'sse_page_two', $run['second']],
                ['view', 'leads.feed', 'sse_page_two', $run['second']],
                ['unsubscribe', 'leads.feed', 'sse_page_two', $run['second']],
            ],
            $run['posts'],
            'the view and the unsubscribe name the id the feed rides now',
        );
        self::assertSame(['row'], $run['frames'], 'frames stamped with the new id reach the same consumer');
        self::assertFalse($run['oldKept'], 'a late frame for the old id is nobody\'s');
        self::assertSame([], $run['left']);
    }

    #[Test]
    public function a_feed_paused_on_a_sessionless_page_comes_back_under_a_new_id(): void
    {
        $run = $this->inNode(<<<'JS'
            var resumed = 0;
            var handle = sseSubscribe({ feed: 'leads.feed' }, {}, function () {}, function () {}, function () { resumed++; });
            var first = handle.subscriptionId;

            session = null;
            rehomeSubscriptions();
            session = 'sse_page_three';
            rehomeSubscriptions();

            out({ first: first, second: handle.subscriptionId, posts: posts, resumed: resumed });
            JS);

        self::assertNotSame($run['first'], $run['second']);
        self::assertSame(
            [
                ['subscribe', 'leads.feed', 'sse_page_one', $run['first']],
                ['subscribe', 'leads.feed', 'sse_page_three', $run['second']],
            ],
            $run['posts'],
        );
        self::assertSame(1, $run['resumed']);
    }

    #[Test]
    public function a_feed_already_on_the_page_s_session_keeps_its_id(): void
    {
        $run = $this->inNode(<<<'JS'
            var handle = sseSubscribe({ feed: 'leads.feed' }, {}, function () {});
            var first = handle.subscriptionId;
            rehomeSubscriptions();
            out({ first: first, second: handle.subscriptionId, posts: posts.length });
            JS);

        self::assertSame($run['first'], $run['second']);
        self::assertSame(1, $run['posts']);
    }

    /**
     * @return array<string, mixed>
     */
    private function inNode(string $scenario): array
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        // Not skipped without node: the image ships it, and a skip would pass a regression.
        self::assertNotSame('', $node, 'node runs the subscription functions; the runtime image ships it.');

        $source = (string) file_get_contents(self::JS);
        $functions = '';
        foreach (['sseSubscribe', 'rehomeSubscriptions', 'pauseSubscriptions'] as $name) {
            self::assertSame(1, preg_match('/^    function ' . $name . '\(.*?^    \}$/ms', $source, $match), $name . '() not found');
            $functions .= ($match[0] ?? '') . "\n";
        }

        $script = <<<'JS'
            var session = 'sse_page_one';
            var minted = 0;
            var posts = [];
            var SSE_SUBSCRIPTIONS = {};
            function EventSource() {}
            function fetch() {}
            function readPageSseSessionId() { return session; }
            function ensureKissOpen() {}
            function mintHexPrefixedId(prefix) { minted++; return prefix + 'minted_' + minted; }
            function postStreamControl(op, feed, params, sessionId, subscriptionId) { posts.push([op, feed, sessionId, subscriptionId]); }
            function reportCallbackError(err) { throw err; }
            function out(value) { process.stdout.write(JSON.stringify(value)); }

            JS . $functions . $scenario;

        $file = (string) tempnam(sys_get_temp_dir(), 'rehome');
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
