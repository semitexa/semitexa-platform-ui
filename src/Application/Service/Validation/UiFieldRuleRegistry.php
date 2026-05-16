<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Validation;

/**
 * Worker-scoped static holder for the active
 * UiFieldRuleRegistryInterface. Mirrors the existing
 * UiPrimitiveRegistry / UiComponentRegistry pattern in this package —
 * bootstrapped once per worker by a container-aware listener
 * (BootPlatformUiRegistryListener), read at render time by the
 * `ui_field_rules` Twig helper and at dispatch time by the
 * UiInteractionDispatcher.
 *
 * Why static, not DI: PlatformUiTwigExtension is instantiated by SSR's
 * TwigExtensionRegistry via reflection's `newInstance()`, so it cannot
 * receive container-injected dependencies. UiFieldRuleRegistry bridges
 * the gap — the boot listener (which the container DOES inject) sets
 * the active registry once, and every render / dispatch path reads it
 * statically.
 *
 * Apps override by binding their own
 * `#[SatisfiesServiceContract(of: UiFieldRuleRegistryInterface::class)]`
 * implementation; the container picks the descendant-module winner;
 * the boot listener stashes that winner here.
 *
 * Test seam: `setActive(...)` lets tests inject a custom registry
 * without going through the container; `reset()` restores the lazy-
 * default path.
 */
final class UiFieldRuleRegistry
{
    private static ?UiFieldRuleRegistryInterface $active = null;

    /**
     * Resolve the active registry. Lazy-defaults to a fresh
     * DefaultUiFieldRuleRegistry when neither the boot listener nor a
     * test has set one — this keeps unit tests that bypass bootstrap
     * (e.g. constructing FieldComponent directly) producing the same
     * "built-ins only" behaviour as the production default.
     */
    public static function getActive(): UiFieldRuleRegistryInterface
    {
        if (self::$active === null) {
            self::$active = new DefaultUiFieldRuleRegistry();
        }
        return self::$active;
    }

    /**
     * Production: called once per worker by
     * BootPlatformUiRegistryListener with the container-bound
     * winner of UiFieldRuleRegistryInterface.
     *
     * Tests: override with a custom registry to drive end-to-end
     * paths (ui_field_rules + FieldComponent dispatch) without
     * touching the container.
     */
    public static function setActive(?UiFieldRuleRegistryInterface $registry): void
    {
        self::$active = $registry;
    }

    /**
     * Test reset hook. Restores the lazy-default behaviour so the
     * next call to getActive() builds a fresh
     * DefaultUiFieldRuleRegistry.
     */
    public static function reset(): void
    {
        self::$active = null;
    }
}
