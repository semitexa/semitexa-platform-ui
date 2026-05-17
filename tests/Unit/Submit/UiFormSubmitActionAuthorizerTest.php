<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Submit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Submit\AllowAllUiFormSubmitActionAuthorizer;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionAuthorizer;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionAuthorizerInterface;
use Semitexa\PlatformUi\Domain\Exception\UiFormSubmitActionAuthorizationException;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionAuthorizationContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitResult;

// Inline helper — composer's autoload-dev does not propagate to the
// test runner's root autoloader, so we build the same fixture every
// test needs without a shared file.

/**
 * Submit action authorizer seam:
 *   - the default impl is a no-op (returns void on every call);
 *   - typed deny exception carries reason code + message and never
 *     leaks class FQCNs;
 *   - the static holder lazy-defaults, accepts overrides, resets;
 *   - the authorization context shape is the documented set of
 *     server-trusted fields.
 */
final class UiFormSubmitActionAuthorizerTest extends TestCase
{
    protected function setUp(): void
    {
        UiFormSubmitActionAuthorizer::reset();
    }

    protected function tearDown(): void
    {
        UiFormSubmitActionAuthorizer::reset();
    }

    private function context(): UiFormSubmitActionAuthorizationContext
    {
        $summary = UiFormSubmitResult::fromFieldResults([
            ['name' => 'access_code', 'state' => 'valid', 'message' => null],
        ]);
        $actionContext = new UiFormSubmitActionContext(
            formInstanceId: 'uci_form_x',
            actionName:     'platform.demo.accept',
            dispatchId:     'ui_evt_unit_authz',
            values:         ['access_code' => 'abcd'],
            fields:         [],
            submitResult:   $summary,
        );
        return new UiFormSubmitActionAuthorizationContext(
            formInstanceId:      'uci_form_x',
            actionName:          'platform.demo.accept',
            dispatchId:          'ui_evt_unit_authz',
            values:              ['access_code' => 'abcd'],
            fields:              [],
            submitResult:        $summary,
            submitActionContext: $actionContext,
        );
    }

    #[Test]
    public function default_allow_authorizer_returns_void_for_every_context(): void
    {
        $this->expectNotToPerformAssertions();
        (new AllowAllUiFormSubmitActionAuthorizer())->authorize($this->context());
    }

    #[Test]
    public function deny_exception_carries_reason_code_and_message(): void
    {
        $e = new UiFormSubmitActionAuthorizationException(
            message: 'You do not have permission to run this action.',
            reasonCode: 'role_required',
        );
        self::assertSame('role_required', $e->reasonCode);
        self::assertSame('You do not have permission to run this action.', $e->getMessage());
        // Default reason code + message exist for callers that
        // construct the exception without args.
        $defaults = new UiFormSubmitActionAuthorizationException();
        self::assertSame('action_forbidden', $defaults->reasonCode);
        self::assertSame('Submit action is not allowed.', $defaults->getMessage());
    }

    #[Test]
    public function deny_exception_message_does_not_leak_class_names(): void
    {
        $e = new UiFormSubmitActionAuthorizationException();
        self::assertStringNotContainsString('AllowAllUiFormSubmitActionAuthorizer', $e->getMessage());
        self::assertStringNotContainsString('Semitexa\\\\', $e->getMessage());
    }

    #[Test]
    public function authorization_context_carries_documented_safe_fields(): void
    {
        $ctx = $this->context();
        self::assertSame('uci_form_x', $ctx->formInstanceId);
        self::assertSame('platform.demo.accept', $ctx->actionName);
        self::assertSame('ui_evt_unit_authz', $ctx->dispatchId);
        self::assertSame(['access_code' => 'abcd'], $ctx->values);
        self::assertSame([], $ctx->fields);
        self::assertInstanceOf(UiFormSubmitResult::class, $ctx->submitResult);
        self::assertInstanceOf(UiFormSubmitActionContext::class, $ctx->submitActionContext);
        // The context does NOT (and cannot) carry the raw SignedContext
        // blob — verify by ensuring no string property looks like a
        // signed-ctx token (prefix `sc1.`).
        foreach (get_object_vars($ctx) as $value) {
            if (is_string($value)) {
                self::assertStringStartsNotWith('sc1.', $value);
            }
        }
    }

    #[Test]
    public function static_holder_lazy_defaults_to_allow_all(): void
    {
        $resolved = UiFormSubmitActionAuthorizer::getActive();
        self::assertInstanceOf(AllowAllUiFormSubmitActionAuthorizer::class, $resolved);
    }

    #[Test]
    public function static_holder_accepts_override_and_reset(): void
    {
        $deny = new class implements UiFormSubmitActionAuthorizerInterface {
            public function authorize(UiFormSubmitActionAuthorizationContext $context): void
            {
                throw new UiFormSubmitActionAuthorizationException('nope', 'unit_test_deny');
            }
        };
        UiFormSubmitActionAuthorizer::setActive($deny);
        self::assertSame($deny, UiFormSubmitActionAuthorizer::getActive());

        UiFormSubmitActionAuthorizer::reset();
        self::assertInstanceOf(
            AllowAllUiFormSubmitActionAuthorizer::class,
            UiFormSubmitActionAuthorizer::getActive(),
        );
    }
}
