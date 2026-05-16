<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Submit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Submit\Action\PlatformDemoAcceptAction;
use Semitexa\PlatformUi\Application\Service\Submit\DefaultUiFormSubmitActionRegistry;
use Semitexa\PlatformUi\Domain\Exception\UiFormSubmitActionException;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitResult;

/**
 * Default registry behaviour:
 *   - knows the platform.demo.accept built-in;
 *   - rejects unknown names with a typed exception;
 *   - never includes class FQCNs or service ids in error messages;
 *   - demo action returns the documented accepted message and no
 *     persistence side effects;
 *   - demo action does not echo raw values in its debug payload.
 */
final class DefaultUiFormSubmitActionRegistryTest extends TestCase
{
    #[Test]
    public function known_action_names_contains_demo_accept(): void
    {
        $registry = new DefaultUiFormSubmitActionRegistry();
        self::assertContains(PlatformDemoAcceptAction::NAME, $registry->knownActionNames());
        self::assertSame(['platform.demo.accept'], $registry->knownActionNames());
    }

    #[Test]
    public function resolves_demo_accept_action_instance(): void
    {
        $registry = new DefaultUiFormSubmitActionRegistry();
        $action = $registry->resolve(PlatformDemoAcceptAction::NAME);
        self::assertInstanceOf(PlatformDemoAcceptAction::class, $action);
        self::assertSame('platform.demo.accept', $action->name());
    }

    #[Test]
    public function unknown_action_throws_typed_exception(): void
    {
        $registry = new DefaultUiFormSubmitActionRegistry();
        $this->expectException(UiFormSubmitActionException::class);
        $this->expectExceptionMessageMatches('/Unknown form submit action/i');
        $registry->resolve('app.never.registered');
    }

    #[Test]
    public function unknown_action_error_does_not_leak_class_or_service_names(): void
    {
        $registry = new DefaultUiFormSubmitActionRegistry();
        try {
            $registry->resolve('app.never.registered');
            self::fail('Expected unknown-action exception.');
        } catch (UiFormSubmitActionException $e) {
            $msg = $e->getMessage();
            self::assertStringNotContainsString('PlatformDemoAcceptAction', $msg);
            self::assertStringNotContainsString('Semitexa\\\\', $msg);
            self::assertStringNotContainsString('::class', $msg);
        }
    }

    #[Test]
    public function demo_action_returns_accepted_message_without_raw_values_in_debug(): void
    {
        $action = (new DefaultUiFormSubmitActionRegistry())->resolve(PlatformDemoAcceptAction::NAME);
        $ctx = new UiFormSubmitActionContext(
            formInstanceId: 'uci_demo_form',
            actionName:     PlatformDemoAcceptAction::NAME,
            dispatchId:     'ui_evt_demo_test',
            values:         ['access_code' => 'leak-canary-XYZ'],
            fields:         [],
            submitResult:   UiFormSubmitResult::fromFieldResults([
                ['name' => 'access_code', 'state' => 'valid', 'message' => null],
            ]),
        );
        $result = $action->handle($ctx);
        self::assertTrue($result->accepted);
        self::assertSame(PlatformDemoAcceptAction::MESSAGE, $result->message);
        self::assertSame('Demo action accepted. No data was persisted.', $result->message);
        $debugJson = json_encode($result->toDebug(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('leak-canary-XYZ', $debugJson);
        self::assertStringNotContainsString('access_code', $debugJson);
        self::assertSame(0, $result->debug['fieldCount']);
        self::assertSame(1, $result->debug['snapshotFieldCount']);
    }
}
