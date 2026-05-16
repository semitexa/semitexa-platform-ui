<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit;

/**
 * Worker-scoped static holder for the active
 * UiFormSubmitActionRegistryInterface.
 *
 * Mirrors the existing UiFieldRuleRegistry / UiPrimitiveRegistry /
 * UiComponentRegistry pattern — bootstrapped once per worker by
 * BootPlatformUiRegistryListener with the container-bound winner,
 * read at render time by the FormComponent Twig template (which is
 * instantiated through reflection by TwigExtensionRegistry, NOT
 * through DI) and at dispatch time by FormComponent::onSubmit().
 *
 * Apps override by declaring their own implementation with
 * `#[SatisfiesServiceContract(of: UiFormSubmitActionRegistryInterface::class)]`
 * in a module that "extends" semitexa-platform-ui; the contract
 * registry picks the descendant-module winner; the boot listener
 * stashes that winner here.
 *
 * Test seam: `setActive(...)` lets tests inject a custom registry
 * without going through the container; `reset()` restores the lazy-
 * default path (a fresh DefaultUiFormSubmitActionRegistry instance).
 */
final class UiFormSubmitActionRegistry
{
    private static ?UiFormSubmitActionRegistryInterface $active = null;

    /**
     * Resolve the active registry. Lazy-defaults to a fresh
     * DefaultUiFormSubmitActionRegistry when neither the boot
     * listener nor a test has set one — this keeps unit tests that
     * bypass bootstrap producing the same "built-ins only" behaviour
     * as production with the default registry.
     */
    public static function getActive(): UiFormSubmitActionRegistryInterface
    {
        if (self::$active === null) {
            self::$active = new DefaultUiFormSubmitActionRegistry();
        }
        return self::$active;
    }

    public static function setActive(?UiFormSubmitActionRegistryInterface $registry): void
    {
        self::$active = $registry;
    }

    public static function reset(): void
    {
        self::$active = null;
    }
}
