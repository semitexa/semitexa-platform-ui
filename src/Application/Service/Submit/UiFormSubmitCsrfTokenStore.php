<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit;

/**
 * Worker-scoped static holder for the active
 * UiFormSubmitCsrfTokenStoreInterface.
 *
 * Same transitional pattern as UiFieldRuleRegistry /
 * UiFormSubmitActionRegistry / UiFormSubmitActionAuthorizer /
 * UiFormSubmitSecurityPolicy — bootstrapped once per worker by
 * BootPlatformUiRegistryListener with the container-bound winner,
 * read at render time by the `ui_form_issue_submit_csrf` Twig helper
 * (instantiated via reflection by TwigExtensionRegistry, NOT through
 * DI) and at dispatch time by CacheBackedUiFormSubmitSecurityPolicy.
 *
 * Lazy-default: {@see InMemoryUiFormSubmitCsrfTokenStore}. Unit tests
 * that bypass bootstrap see a worker-local store; the active-store
 * pattern keeps test wiring trivial.
 *
 * Test seam: `setActive(...)` lets tests inject a custom store
 * (typically a recording fake) without going through the container.
 * `reset()` restores the lazy-default path.
 */
final class UiFormSubmitCsrfTokenStore
{
    private static ?UiFormSubmitCsrfTokenStoreInterface $active = null;

    public static function getActive(): UiFormSubmitCsrfTokenStoreInterface
    {
        if (self::$active === null) {
            self::$active = new InMemoryUiFormSubmitCsrfTokenStore();
        }
        return self::$active;
    }

    public static function setActive(?UiFormSubmitCsrfTokenStoreInterface $store): void
    {
        self::$active = $store;
    }

    public static function reset(): void
    {
        self::$active = null;
    }
}
