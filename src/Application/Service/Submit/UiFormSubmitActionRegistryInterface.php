<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit;

use Semitexa\PlatformUi\Domain\Exception\UiFormSubmitActionException;

/**
 * Resolves a server-trusted action name into the
 * UiFormSubmitActionInterface instance that handles it.
 *
 * Mirrors the {@see UiFieldRuleRegistryInterface} pattern exactly:
 *
 *   - Apps register their own implementation through
 *     `#[SatisfiesServiceContract(of: UiFormSubmitActionRegistryInterface::class)]`
 *     in a module that "extends" semitexa-platform-ui.
 *   - The contract registry's module-order winner is picked at
 *     container build; BootPlatformUiRegistryListener then stashes
 *     that winner in {@see UiFormSubmitActionRegistry} so the Twig
 *     extension (instantiated through reflection, not DI) and the
 *     FormComponent submit handler can both read the same registry.
 *
 * Security perimeter:
 *
 *   - Implementations MUST NOT instantiate a class derived from the
 *     action name through reflection / `class_exists` / service lookup.
 *     A custom registry that maps `name → new MyAction()` via a hard-
 *     coded `match` is safe; one that does `new ($name)()` is NOT.
 *   - `resolve()` MUST throw {@see UiFormSubmitActionException} on
 *     unknown names; the FormComponent submit handler wraps that into
 *     a safe response without leaking the bad value or any class
 *     references.
 *   - Error messages MUST NOT include class FQCNs, service ids, or
 *     other implementation detail.
 */
interface UiFormSubmitActionRegistryInterface
{
    /**
     * Resolve a registered action by its public name.
     *
     * @throws UiFormSubmitActionException when no action is registered
     *         under the given name.
     */
    public function resolve(string $actionName): UiFormSubmitActionInterface;

    /**
     * Sorted list of action names this registry knows about. Used by
     * the FormComponent template at render time to validate the
     * `submitAction` prop before signing it into `cfg.a`, and by the
     * registry's own "unknown action" diagnostics. Order is the
     * registry's choice; the default returns built-ins in declaration
     * order.
     *
     * @return list<string>
     */
    public function knownActionNames(): array;
}
