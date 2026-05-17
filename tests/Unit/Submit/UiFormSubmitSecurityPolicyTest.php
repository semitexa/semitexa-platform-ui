<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Submit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Submit\SignedContextOnlyUiFormSubmitSecurityPolicy;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitSecurityPolicy;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitSecurityPolicyInterface;
use Semitexa\PlatformUi\Domain\Exception\UiFormSubmitSecurityPolicyException;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitResult;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitSecurityContext;

/**
 * Submit security/CSRF policy seam:
 *   - default impl is the documented signed-ctx-only no-op;
 *   - typed failure exception carries reason code + message;
 *   - the static holder lazy-defaults / setActive / reset;
 *   - the security context shape is the documented narrow set
 *     (no submitted values).
 */
final class UiFormSubmitSecurityPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        UiFormSubmitSecurityPolicy::reset();
    }

    protected function tearDown(): void
    {
        UiFormSubmitSecurityPolicy::reset();
    }

    private function context(): UiFormSubmitSecurityContext
    {
        return new UiFormSubmitSecurityContext(
            formInstanceId: 'uci_form_y',
            actionName:     'platform.demo.accept',
            dispatchId:     'ui_evt_unit_security',
            fields:         [],
            submitResult:   UiFormSubmitResult::fromFieldResults([
                ['name' => 'access_code', 'state' => 'valid', 'message' => null],
            ]),
        );
    }

    #[Test]
    public function default_policy_returns_void_for_every_context(): void
    {
        (new SignedContextOnlyUiFormSubmitSecurityPolicy())->verify($this->context());
        self::assertTrue(true);
    }

    #[Test]
    public function failure_exception_carries_reason_code_and_message(): void
    {
        $e = new UiFormSubmitSecurityPolicyException(
            message: 'Form security token has expired.',
            reasonCode: 'csrf_verification_failed',
        );
        self::assertSame('csrf_verification_failed', $e->reasonCode);
        self::assertSame('Form security token has expired.', $e->getMessage());
        $defaults = new UiFormSubmitSecurityPolicyException();
        self::assertSame('submit_security_failed', $defaults->reasonCode);
        self::assertSame('Submit security policy denied this submission.', $defaults->getMessage());
    }

    #[Test]
    public function security_context_carries_narrow_safe_fields_only(): void
    {
        $ctx = $this->context();
        self::assertSame('uci_form_y', $ctx->formInstanceId);
        self::assertSame('platform.demo.accept', $ctx->actionName);
        self::assertSame('ui_evt_unit_security', $ctx->dispatchId);
        self::assertSame([], $ctx->fields);
        self::assertSame([], $ctx->securityConfig);
        // The narrow context intentionally OMITS `values` — a CSRF /
        // session check should not need them. Pin by reflecting over
        // the readonly properties.
        $props = array_keys(get_object_vars($ctx));
        self::assertSame(
            ['formInstanceId', 'actionName', 'dispatchId', 'fields', 'submitResult', 'securityConfig'],
            $props,
        );
    }

    #[Test]
    public function static_holder_lazy_defaults_to_signed_context_only(): void
    {
        self::assertInstanceOf(
            SignedContextOnlyUiFormSubmitSecurityPolicy::class,
            UiFormSubmitSecurityPolicy::getActive(),
        );
    }

    #[Test]
    public function static_holder_accepts_override_and_reset(): void
    {
        $fail = new class implements UiFormSubmitSecurityPolicyInterface {
            public function verify(UiFormSubmitSecurityContext $context): void
            {
                throw new UiFormSubmitSecurityPolicyException('csrf bad', 'csrf_verification_failed');
            }
        };
        UiFormSubmitSecurityPolicy::setActive($fail);
        self::assertSame($fail, UiFormSubmitSecurityPolicy::getActive());

        UiFormSubmitSecurityPolicy::reset();
        self::assertInstanceOf(
            SignedContextOnlyUiFormSubmitSecurityPolicy::class,
            UiFormSubmitSecurityPolicy::getActive(),
        );
    }
}
