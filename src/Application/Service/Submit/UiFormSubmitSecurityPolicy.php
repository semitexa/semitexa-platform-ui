<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit;

/**
 * Worker-scoped static holder for the active
 * UiFormSubmitSecurityPolicyInterface.
 *
 * Mirrors UiFormSubmitActionAuthorizer and the other static holders
 * in this package — bootstrapped once per worker by
 * BootPlatformUiRegistryListener with the container-bound winner,
 * read at dispatch time by FormComponent::onSubmit().
 *
 * Apps override by declaring their own implementation with
 * `#[SatisfiesServiceContract(of: UiFormSubmitSecurityPolicyInterface::class)]`
 * in a module that "extends" semitexa-platform-ui.
 *
 * Test seam: `setActive(...)` lets tests inject a custom policy
 * (typically a throwing fake) without going through the container.
 * `reset()` restores the lazy-default path.
 */
final class UiFormSubmitSecurityPolicy
{
    private static ?UiFormSubmitSecurityPolicyInterface $active = null;

    /**
     * Resolve the active policy. Lazy-defaults to a fresh
     * SignedContextOnlyUiFormSubmitSecurityPolicy when neither the
     * boot listener nor a test has set one.
     */
    public static function getActive(): UiFormSubmitSecurityPolicyInterface
    {
        if (self::$active === null) {
            self::$active = new SignedContextOnlyUiFormSubmitSecurityPolicy();
        }
        return self::$active;
    }

    public static function setActive(?UiFormSubmitSecurityPolicyInterface $policy): void
    {
        self::$active = $policy;
    }

    public static function reset(): void
    {
        self::$active = null;
    }
}
