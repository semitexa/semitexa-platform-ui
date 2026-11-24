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
     * @return array<string, mixed>
     */
    private function inNode(string $scenario): array
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        // Not skipped without node: the image ships it, and a skip would pass a regression.
        self::assertNotSame('', $node, 'node runs buildKissUrl; the runtime image ships it.');

        $source = (string) file_get_contents(self::JS);
        self::assertSame(1, preg_match('/^    function buildKissUrl\(.*?^    \}$/ms', $source, $match), 'buildKissUrl() not found');

        $script = <<<'JS'
            function readDeferredManifest() { return { requestId: 'dr_1' }; }
            function out(value) { process.stdout.write(JSON.stringify(value)); }

            JS . ($match[0] ?? '') . "\n" . $scenario;

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
