<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Submit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Submit\AllowAllUiDemoSubmissionAdminAuthorizer;
use Semitexa\PlatformUi\Application\Service\Submit\UiDemoSubmissionAdminAuthorizer;
use Semitexa\PlatformUi\Application\Service\Submit\UiDemoSubmissionAdminAuthorizerInterface;
use Semitexa\PlatformUi\Domain\Exception\UiDemoSubmissionAdminAuthorizationException;

/**
 * Diagnostic listing authorizer seam:
 *   - default impl allows (no throw);
 *   - typed exception carries reason code + message;
 *   - failure surface does not leak class FQCNs;
 *   - static holder lazy-defaults / setActive / reset.
 */
final class UiDemoSubmissionAdminAuthorizerTest extends TestCase
{
    protected function setUp(): void
    {
        UiDemoSubmissionAdminAuthorizer::reset();
    }

    protected function tearDown(): void
    {
        UiDemoSubmissionAdminAuthorizer::reset();
    }

    #[Test]
    public function default_authorizer_allows_every_call(): void
    {
        (new AllowAllUiDemoSubmissionAdminAuthorizer())->authorize();
        self::assertTrue(true);
    }

    #[Test]
    public function deny_exception_carries_reason_code_and_message(): void
    {
        $e = new UiDemoSubmissionAdminAuthorizationException(
            message: 'You do not have access to demo submission diagnostics.',
            reasonCode: 'role_required',
        );
        self::assertSame('role_required', $e->reasonCode);
        self::assertSame('You do not have access to demo submission diagnostics.', $e->getMessage());

        $defaults = new UiDemoSubmissionAdminAuthorizationException();
        self::assertSame('demo_admin_forbidden', $defaults->reasonCode);
        self::assertSame('Diagnostic listing access is denied.', $defaults->getMessage());
    }

    #[Test]
    public function deny_exception_does_not_leak_class_or_service_names(): void
    {
        $e = new UiDemoSubmissionAdminAuthorizationException();
        self::assertStringNotContainsString('AllowAllUiDemoSubmissionAdminAuthorizer', $e->getMessage());
        self::assertStringNotContainsString('Semitexa\\\\', $e->getMessage());
    }

    #[Test]
    public function static_holder_lazy_defaults_to_allow_all(): void
    {
        self::assertInstanceOf(
            AllowAllUiDemoSubmissionAdminAuthorizer::class,
            UiDemoSubmissionAdminAuthorizer::getActive(),
        );
    }

    #[Test]
    public function static_holder_accepts_override_and_reset(): void
    {
        $deny = new class implements UiDemoSubmissionAdminAuthorizerInterface {
            public function authorize(): void
            {
                throw new UiDemoSubmissionAdminAuthorizationException('nope', 'unit_test_deny');
            }
        };
        UiDemoSubmissionAdminAuthorizer::setActive($deny);
        self::assertSame($deny, UiDemoSubmissionAdminAuthorizer::getActive());

        UiDemoSubmissionAdminAuthorizer::reset();
        self::assertInstanceOf(
            AllowAllUiDemoSubmissionAdminAuthorizer::class,
            UiDemoSubmissionAdminAuthorizer::getActive(),
        );
    }
}
