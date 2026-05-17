<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Submit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Submit\ConfigurableUiDemoSubmissionAdminAuthorizer;
use Semitexa\PlatformUi\Domain\Exception\UiDemoSubmissionAdminAuthorizationException;

/**
 * Protected-mode authorizer contract:
 *
 *   - flag whitelist (`1`/`true`/`yes`/`on`/`enabled`, case-insensitive
 *     after trim) → allow;
 *   - everything else (unset, empty, falsey strings, random text) →
 *     throw `UiDemoSubmissionAdminAuthorizationException` with
 *     `reasonCode: demo_admin_disabled`;
 *   - exception message + reasonCode never echo the bad env value
 *     or the env-flag name back.
 */
final class ConfigurableUiDemoSubmissionAdminAuthorizerTest extends TestCase
{
    private ?string $previousFlag = null;

    protected function setUp(): void
    {
        $current = getenv(ConfigurableUiDemoSubmissionAdminAuthorizer::ENV_FLAG);
        $this->previousFlag = $current === false ? null : $current;
        putenv(ConfigurableUiDemoSubmissionAdminAuthorizer::ENV_FLAG);
    }

    protected function tearDown(): void
    {
        if ($this->previousFlag === null) {
            putenv(ConfigurableUiDemoSubmissionAdminAuthorizer::ENV_FLAG);
        } else {
            putenv(ConfigurableUiDemoSubmissionAdminAuthorizer::ENV_FLAG . '=' . $this->previousFlag);
        }
    }

    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function envFlagMatrix(): iterable
    {
        // Truthy — allow.
        yield 'one'           => ['1',        true];
        yield 'true_lower'    => ['true',     true];
        yield 'TRUE_upper'    => ['TRUE',     true];
        yield 'True_mixed'    => ['True',     true];
        yield 'yes'           => ['yes',      true];
        yield 'YES'           => ['YES',      true];
        yield 'on'            => ['on',       true];
        yield 'enabled'       => ['enabled',  true];
        yield 'true_padded'   => ['  true  ', true];

        // Falsey — deny.
        yield 'empty'         => ['',         false];
        yield 'zero'          => ['0',        false];
        yield 'false'         => ['false',    false];
        yield 'FALSE'         => ['FALSE',    false];
        yield 'off'           => ['off',      false];
        yield 'no'            => ['no',       false];
        yield 'disabled'      => ['disabled', false];
        yield 'random_string' => ['maybe',    false];
        yield 'whitespace'    => ['    ',     false];
    }

    #[DataProvider('envFlagMatrix')]
    #[Test]
    public function env_flag_matrix(string $envValue, bool $allowed): void
    {
        putenv(ConfigurableUiDemoSubmissionAdminAuthorizer::ENV_FLAG . '=' . $envValue);
        self::assertSame($allowed, ConfigurableUiDemoSubmissionAdminAuthorizer::isEnabled());

        $authorizer = new ConfigurableUiDemoSubmissionAdminAuthorizer();
        if ($allowed) {
            $authorizer->authorize();
            self::assertTrue(true); // no throw → allow path
            return;
        }
        try {
            $authorizer->authorize();
            self::fail('Expected demo_admin_disabled denial for env value: ' . var_export($envValue, true));
        } catch (UiDemoSubmissionAdminAuthorizationException $e) {
            self::assertSame('demo_admin_disabled', $e->reasonCode);
        }
    }

    #[Test]
    public function unset_env_flag_denies(): void
    {
        putenv(ConfigurableUiDemoSubmissionAdminAuthorizer::ENV_FLAG);
        self::assertFalse(ConfigurableUiDemoSubmissionAdminAuthorizer::isEnabled());
        $this->expectException(UiDemoSubmissionAdminAuthorizationException::class);
        (new ConfigurableUiDemoSubmissionAdminAuthorizer())->authorize();
    }

    #[Test]
    public function denial_message_does_not_leak_env_value_or_class_names(): void
    {
        putenv(ConfigurableUiDemoSubmissionAdminAuthorizer::ENV_FLAG . '=leak-canary-XYZ');
        try {
            (new ConfigurableUiDemoSubmissionAdminAuthorizer())->authorize();
            self::fail('Expected denial.');
        } catch (UiDemoSubmissionAdminAuthorizationException $e) {
            $msg = $e->getMessage();
            self::assertStringNotContainsString('leak-canary-XYZ', $msg);
            self::assertStringNotContainsString('PLATFORM_UI_DEMO_ADMIN_ENABLED', $msg);
            self::assertStringNotContainsString('ConfigurableUiDemoSubmissionAdminAuthorizer', $msg);
            self::assertStringNotContainsString('Semitexa\\\\', $msg);
            self::assertSame(
                'Diagnostic listing access is disabled. An operator must enable it explicitly.',
                $msg,
            );
        }
    }

    #[Test]
    public function authorizer_is_NOT_the_default_service_contract_winner(): void
    {
        // Strategy 1 pin: the package's default binding remains
        // AllowAllUiDemoSubmissionAdminAuthorizer. The configurable
        // one is opt-in only — production apps wire it via their
        // own SatisfiesServiceContract or via setActive() from a
        // custom boot listener.
        $reflection = new \ReflectionClass(ConfigurableUiDemoSubmissionAdminAuthorizer::class);
        $satisfies = $reflection->getAttributes(\Semitexa\Core\Attribute\SatisfiesServiceContract::class);
        self::assertSame([], $satisfies, 'Configurable authorizer must NOT carry SatisfiesServiceContract.');
    }
}
