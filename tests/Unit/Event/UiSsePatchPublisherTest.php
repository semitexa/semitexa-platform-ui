<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Event\InMemoryUiSsePatchQueue;
use Semitexa\PlatformUi\Application\Service\Event\UiPatchValidator;
use Semitexa\PlatformUi\Application\Service\Event\UiSsePatchPublisher;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionUnprocessableException;
use Semitexa\PlatformUi\Domain\Model\Event\UiResponsePatch;

final class UiSsePatchPublisherTest extends TestCase
{
    private function newPublisher(): array
    {
        // The container instantiates the publisher via
        // newInstanceWithoutConstructor(), then property-injects $queue.
        // We mirror that path: no constructor, explicit dependency
        // wiring via withDependencies().
        $queue = new InMemoryUiSsePatchQueue();
        $publisher = (new \ReflectionClass(UiSsePatchPublisher::class))
            ->newInstanceWithoutConstructor()
            ->withDependencies($queue, new UiPatchValidator());
        return [$publisher, $queue];
    }

    private function setTextPatch(string $instance, string $value = 'hi'): UiResponsePatch
    {
        return new UiResponsePatch(
            op: UiResponsePatch::OP_SET_TEXT,
            targetInstance: $instance,
            targetPart: null,
            targetName: 'server-ack',
            value: $value,
        );
    }

    #[Test]
    public function publishes_serialised_message_using_response_patch_shape(): void
    {
        [$publisher, $queue] = $this->newPublisher();
        $publisher->publish('uch_demo_chan_0001', 'uci_demo_inst_0001', [
            $this->setTextPatch('uci_demo_inst_0001', 'value-one'),
        ]);
        $batch = $queue->drain('uch_demo_chan_0001', 10);
        self::assertCount(1, $batch);

        $decoded = json_decode($batch[0], true);
        self::assertIsArray($decoded);
        self::assertSame(UiSsePatchPublisher::MESSAGE_VERSION, $decoded['v']);
        self::assertSame(1, count($decoded['patches']));
        self::assertSame('setText', $decoded['patches'][0]['op']);
        self::assertSame('uci_demo_inst_0001', $decoded['patches'][0]['target']['instance']);
        self::assertSame('server-ack', $decoded['patches'][0]['target']['name']);
        self::assertSame('value-one', $decoded['patches'][0]['value']);
        self::assertSame(1, preg_match('/\Auchm_[0-9a-f]{32}\z/', $decoded['messageId']));
        self::assertIsInt($decoded['publishedAt']);
    }

    #[Test]
    public function rejects_patch_targeting_a_different_instance(): void
    {
        [$publisher, ] = $this->newPublisher();
        $this->expectException(UiInteractionUnprocessableException::class);
        try {
            $publisher->publish('uch_demo_chan_0001', 'uci_legit_aaa', [
                $this->setTextPatch('uci_other_bbb', 'attack'),
            ]);
        } catch (UiInteractionUnprocessableException $e) {
            self::assertSame('patch_instance_mismatch', $e->reason);
            throw $e;
        }
    }

    #[Test]
    public function rejects_unknown_patch_op(): void
    {
        [$publisher, $queue] = $this->newPublisher();
        $bad = new UiResponsePatch(
            op: 'setHtml',                                  // not on the allow-list
            targetInstance: 'uci_demo_inst_0001',
            targetPart: null,
            targetName: 'server-ack',
            value: '<script>alert(1)</script>',
        );
        $this->expectException(UiInteractionUnprocessableException::class);
        try {
            $publisher->publish('uch_demo_chan_0001', 'uci_demo_inst_0001', [$bad]);
        } catch (UiInteractionUnprocessableException $e) {
            self::assertSame('invalid_patch_op', $e->reason);
            // Defensive: queue must not have leaked any payload.
            self::assertSame([], $queue->drain('uch_demo_chan_0001', 10));
            throw $e;
        }
    }

    #[Test]
    public function rejects_too_many_patches_in_a_single_message(): void
    {
        [$publisher, ] = $this->newPublisher();
        $instance = 'uci_demo_inst_0001';
        $patches = [];
        for ($i = 0; $i <= UiSsePatchPublisher::MAX_PATCHES_PER_MESSAGE; $i++) {
            $patches[] = $this->setTextPatch($instance, 'v' . $i);
        }
        $this->expectException(UiInteractionUnprocessableException::class);
        try {
            $publisher->publish('uch_demo_chan_0001', $instance, $patches);
        } catch (UiInteractionUnprocessableException $e) {
            self::assertSame('too_many_patches', $e->reason);
            throw $e;
        }
    }

    #[Test]
    public function rejects_malformed_channel_id(): void
    {
        [$publisher, ] = $this->newPublisher();
        $this->expectException(\InvalidArgumentException::class);
        $publisher->publish('bad channel id', 'uci_demo_inst_0001', [
            $this->setTextPatch('uci_demo_inst_0001'),
        ]);
    }

    #[Test]
    public function empty_patch_list_is_no_op(): void
    {
        [$publisher, $queue] = $this->newPublisher();
        $publisher->publish('uch_demo_chan_0001', 'uci_demo_inst_0001', []);
        self::assertSame([], $queue->drain('uch_demo_chan_0001', 10));
    }

    #[Test]
    public function message_shape_contains_no_class_or_handler_names(): void
    {
        [$publisher, $queue] = $this->newPublisher();
        $publisher->publish('uch_demo_chan_0001', 'uci_demo_inst_0001', [
            $this->setTextPatch('uci_demo_inst_0001'),
        ]);
        $raw = $queue->drain('uch_demo_chan_0001', 1)[0];
        self::assertStringNotContainsString('Handler', $raw);
        self::assertStringNotContainsString('FieldComponent', $raw);
        self::assertStringNotContainsString('Semitexa\\\\PlatformUi', $raw);
    }
}
