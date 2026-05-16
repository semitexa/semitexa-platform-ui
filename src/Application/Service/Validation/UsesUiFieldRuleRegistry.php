<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Validation;

/**
 * Opt-in interface for Platform UI components that want to resolve
 * validation rules through a specific UiFieldRuleRegistryInterface
 * instance at dispatch time.
 *
 * UiInteractionDispatcher detects this interface after instantiating
 * a component (via reflection's `newInstance()`) and calls
 * `withFieldRuleRegistry($active)` so the handler method can read
 * the registry from `$this` instead of pulling from the static
 * UiFieldRuleRegistry holder.
 *
 * Why an opt-in interface (not a mandatory base class): most
 * components do NOT need validation rules and shouldn't carry the
 * obligation. FieldComponent does, so it implements this. New
 * components that compose FieldComponent or do their own validation
 * opt in by implementing the interface.
 *
 * This is the documented "transitional bridge" called out in the
 * package boundary audit (G1) — once Semitexa lands DI-managed
 * component instances, the dispatcher can drop the manual setter call
 * and components can use `#[InjectAsReadonly]` directly. The
 * interface stays in place to keep API surface stable.
 */
interface UsesUiFieldRuleRegistry
{
    /**
     * Receive the registry that should be used for this dispatch.
     * Implementations MUST return $this (or a clone with the registry
     * applied) — the dispatcher reassigns the returned value.
     *
     * Implementations MUST NOT throw; the dispatcher calls this
     * unconditionally on any component that opts in. If a component
     * decides to ignore the registry, it can return $this unchanged.
     */
    public function withFieldRuleRegistry(UiFieldRuleRegistryInterface $registry): static;
}
