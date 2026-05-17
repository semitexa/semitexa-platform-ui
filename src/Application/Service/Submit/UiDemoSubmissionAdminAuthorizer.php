<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit;

/**
 * Worker-scoped static holder for the active
 * UiDemoSubmissionAdminAuthorizerInterface.
 *
 * Same transitional bridge pattern used across this package
 * ({@see UiFormSubmitActionAuthorizer}, etc.) —
 * BootPlatformUiRegistryListener stashes the container-bound winner
 * here once per worker; the diagnostic handler reads it via
 * `getActive()` at request time.
 *
 * Lazy-default: {@see AllowAllUiDemoSubmissionAdminAuthorizer}.
 * Test seam: `setActive(...)` lets tests inject a custom
 * (throwing) fake without going through the container; `reset()`
 * restores the lazy-default path.
 */
final class UiDemoSubmissionAdminAuthorizer
{
    private static ?UiDemoSubmissionAdminAuthorizerInterface $active = null;

    public static function getActive(): UiDemoSubmissionAdminAuthorizerInterface
    {
        if (self::$active === null) {
            self::$active = new AllowAllUiDemoSubmissionAdminAuthorizer();
        }
        return self::$active;
    }

    public static function setActive(?UiDemoSubmissionAdminAuthorizerInterface $authorizer): void
    {
        self::$active = $authorizer;
    }

    public static function reset(): void
    {
        self::$active = null;
    }
}
