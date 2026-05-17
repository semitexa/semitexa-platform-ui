<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit;

/**
 * Worker-scoped static holder for the active
 * UiFormSubmitActionAuthorizerInterface.
 *
 * Mirrors the existing UiFieldRuleRegistry /
 * UiFormSubmitActionRegistry pattern — bootstrapped once per worker
 * by BootPlatformUiRegistryListener with the container-bound winner,
 * read at dispatch time by FormComponent::onSubmit() (which is
 * instantiated through reflection by UiInteractionDispatcher, NOT
 * through DI).
 *
 * Apps override by declaring their own implementation with
 * `#[SatisfiesServiceContract(of: UiFormSubmitActionAuthorizerInterface::class)]`
 * in a module that "extends" semitexa-platform-ui.
 *
 * Test seam: `setActive(...)` lets tests inject a custom authorizer
 * (typically a `throw new UiFormSubmitActionAuthorizationException(...)`
 * fake) without going through the container. `reset()` restores the
 * lazy-default path.
 */
final class UiFormSubmitActionAuthorizer
{
    private static ?UiFormSubmitActionAuthorizerInterface $active = null;

    /**
     * Resolve the active authorizer. Lazy-defaults to a fresh
     * AllowAllUiFormSubmitActionAuthorizer when neither the boot
     * listener nor a test has set one.
     */
    public static function getActive(): UiFormSubmitActionAuthorizerInterface
    {
        if (self::$active === null) {
            self::$active = new AllowAllUiFormSubmitActionAuthorizer();
        }
        return self::$active;
    }

    public static function setActive(?UiFormSubmitActionAuthorizerInterface $authorizer): void
    {
        self::$active = $authorizer;
    }

    public static function reset(): void
    {
        self::$active = null;
    }
}
