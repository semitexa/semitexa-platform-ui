<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Event\UiPayloadFieldGuard;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionBadRequestException;

final class UiPayloadFieldGuardTest extends TestCase
{
    /** @return iterable<string, array{0: array<string,mixed>, 1: string}> */
    public static function forbiddenTopLevel(): iterable
    {
        yield 'handler'        => [['handler' => 'x'], 'payload.handler'];
        yield 'handler_id'     => [['handler_id' => 'x'], 'payload.handler_id'];
        yield 'handler-id'     => [['handler-id' => 'x'], 'payload.handler-id'];
        yield 'handlerId'      => [['handlerId' => 'x'], 'payload.handlerId'];
        yield 'method'         => [['method' => 'x'], 'payload.method'];
        yield 'methodName'     => [['methodName' => 'x'], 'payload.methodName'];
        yield 'method_name'    => [['method_name' => 'x'], 'payload.method_name'];
        yield 'class'          => [['class' => 'x'], 'payload.class'];
        yield 'className'      => [['className' => 'x'], 'payload.className'];
        yield 'component'      => [['component' => 'x'], 'payload.component'];
        yield 'componentName'  => [['componentName' => 'x'], 'payload.componentName'];
        yield 'part'           => [['part' => 'x'], 'payload.part'];
        yield 'partName'       => [['partName' => 'x'], 'payload.partName'];
        yield 'event'          => [['event' => 'x'], 'payload.event'];
        yield 'eventName'      => [['eventName' => 'x'], 'payload.eventName'];
        yield 'endpoint'       => [['endpoint' => 'x'], 'payload.endpoint'];
        yield 'url'            => [['url' => 'x'], 'payload.url'];
        yield 'action'         => [['action' => 'x'], 'payload.action'];
        yield 'submitAction'   => [['submitAction' => 'x'], 'payload.submitAction'];
        yield 'submit_action'  => [['submit_action' => 'x'], 'payload.submit_action'];
        yield 'submit-action'  => [['submit-action' => 'x'], 'payload.submit-action'];
        yield 'dispatcher'     => [['dispatcher' => 'x'], 'payload.dispatcher'];
        yield 'route'          => [['route' => 'x'], 'payload.route'];
        yield 'controller'     => [['controller' => 'x'], 'payload.controller'];
        yield 'callback'       => [['callback' => 'x'], 'payload.callback'];
        yield 'updates'        => [['updates' => 'x'], 'payload.updates'];
        yield 'updatesPath'    => [['updatesPath' => 'x'], 'payload.updatesPath'];
    }

    #[DataProvider('forbiddenTopLevel')]
    #[Test]
    public function rejects_forbidden_top_level_keys(array $payload, string $expectedPath): void
    {
        $this->expectException(UiInteractionBadRequestException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expectedPath, '/') . '/');
        (new UiPayloadFieldGuard())->assertSafe($payload);
    }

    #[Test]
    public function rejects_nested_forbidden_keys_inside_objects(): void
    {
        $this->expectException(UiInteractionBadRequestException::class);
        $this->expectExceptionMessageMatches('/payload\.meta\.handler/');
        (new UiPayloadFieldGuard())->assertSafe([
            'value' => 'x',
            'meta' => ['handler' => 'Some\\Class::method'],
        ]);
    }

    #[Test]
    public function rejects_forbidden_keys_inside_list_entries(): void
    {
        $this->expectException(UiInteractionBadRequestException::class);
        $this->expectExceptionMessageMatches('/payload\.items\.0\.method/');
        (new UiPayloadFieldGuard())->assertSafe([
            'items' => [
                ['method' => 'evil()'],
            ],
        ]);
    }

    #[Test]
    public function accepts_safe_value_only_payload(): void
    {
        (new UiPayloadFieldGuard())->assertSafe(['value' => 'hello@example.com']);
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function accepts_empty_payload(): void
    {
        (new UiPayloadFieldGuard())->assertSafe([]);
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function accepts_payload_with_deep_safe_nesting(): void
    {
        (new UiPayloadFieldGuard())->assertSafe([
            'value' => 'x',
            'meta' => [
                'choices' => ['a', 'b'],
                'extra' => ['note' => 'safe', 'count' => 3],
            ],
        ]);
        $this->expectNotToPerformAssertions();
    }
}
