<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit;

/**
 * Worker-scoped static holder for the active
 * UiFormDatabaseDemoSubmissionRepositoryInterface.
 *
 * Same transitional pattern as
 * {@see UiFormDemoSubmissionRepository},
 * {@see UiFormSubmitActionRegistry}, etc. —
 * BootPlatformUiRegistryListener stashes the container-bound winner
 * here once per worker; the registry pulls it via `getActive()` at
 * action-resolve time so the action class itself stays free of any
 * container access.
 *
 * Lazy-default: {@see InMemoryUiFormDatabaseDemoSubmissionRepository}.
 * That keeps unit tests that bypass bootstrap working without a real
 * database — they drive `save()` / `find()` through the in-memory
 * fallback and dispatch tests can assert exact stored shapes.
 */
final class UiFormDatabaseDemoSubmissionRepository
{
    private static ?UiFormDatabaseDemoSubmissionRepositoryInterface $active = null;

    public static function getActive(): UiFormDatabaseDemoSubmissionRepositoryInterface
    {
        if (self::$active === null) {
            self::$active = new InMemoryUiFormDatabaseDemoSubmissionRepository();
        }
        return self::$active;
    }

    public static function setActive(?UiFormDatabaseDemoSubmissionRepositoryInterface $repository): void
    {
        self::$active = $repository;
    }

    public static function reset(): void
    {
        self::$active = null;
    }
}
