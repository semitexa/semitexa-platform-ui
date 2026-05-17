<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit;

/**
 * Worker-scoped static holder for the active
 * UiFormDemoSubmissionRepositoryInterface.
 *
 * Same transitional pattern as UiFormSubmitActionRegistry /
 * UiFormSubmitActionAuthorizer / UiFormSubmitSecurityPolicy /
 * UiFormSubmitCsrfTokenStore — bootstrapped once per worker by
 * BootPlatformUiRegistryListener with the container-bound winner,
 * read by {@see DefaultUiFormSubmitActionRegistry} when it
 * instantiates {@see Action\PlatformDemoStoreContactAction}.
 *
 * Lazy-default: {@see InMemoryUiFormDemoSubmissionRepository}. Unit
 * tests that bypass bootstrap see a worker-local store; the
 * active-store pattern keeps test wiring trivial.
 *
 * Test seam: `setActive(...)` lets tests inject a recording or
 * pre-populated fake. `reset()` restores the lazy-default path.
 */
final class UiFormDemoSubmissionRepository
{
    private static ?UiFormDemoSubmissionRepositoryInterface $active = null;

    public static function getActive(): UiFormDemoSubmissionRepositoryInterface
    {
        if (self::$active === null) {
            self::$active = new InMemoryUiFormDemoSubmissionRepository();
        }
        return self::$active;
    }

    public static function setActive(?UiFormDemoSubmissionRepositoryInterface $repository): void
    {
        self::$active = $repository;
    }

    public static function reset(): void
    {
        self::$active = null;
    }
}
