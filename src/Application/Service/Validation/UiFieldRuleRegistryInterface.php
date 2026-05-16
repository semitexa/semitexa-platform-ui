<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Validation;

use Semitexa\PlatformUi\Domain\Exception\UiFieldValidationRuleException;
use Semitexa\PlatformUi\Domain\Model\Event\UiFieldRuleSpec;

/**
 * Resolves a normalized UiFieldRuleSpec into a live
 * UiFieldValidationRuleInterface instance.
 *
 * The registry is the single source of truth for:
 *   - which rule names are accepted (`knownRuleNames()`);
 *   - the per-rule parameter contract (param count + type);
 *   - which concrete class implements each rule.
 *
 * `UiFieldRuleParser` keeps responsibility for the structural DSL
 * checks (list shape, scalar params, non-empty name) and delegates the
 * name→object mapping here. Apps that want to add their own rules
 * register a class with `#[SatisfiesServiceContract(of:
 * UiFieldRuleRegistryInterface::class)]` in a module that "extends"
 * `semitexa-platform-ui`; the contract registry's module-order winner
 * picks the descendant. Compose with the default registry to inherit
 * the three built-ins — see DefaultUiFieldRuleRegistry's doctype block
 * for the canonical example.
 *
 * Security perimeter: the registry MUST NOT instantiate a class
 * derived from a rule name through reflection / `class_exists` /
 * service lookup. A custom registry that maps `name → new MyRule()`
 * via a hardcoded switch is safe. A custom registry that does
 * `new ($spec->name)(...)` is NOT — it would let render-time DSL
 * smuggle arbitrary FQCNs. The default implementation uses a fixed
 * `match` expression for this reason.
 */
interface UiFieldRuleRegistryInterface
{
    /**
     * Construct a rule instance for $spec. Throws
     * UiFieldValidationRuleException when:
     *   - the rule name is unknown;
     *   - the parameter list does not match the rule's contract;
     *   - the rule's own constructor rejects the parameters
     *     (e.g. MinLengthRule rejects negative min).
     *
     * @throws UiFieldValidationRuleException
     */
    public function resolve(UiFieldRuleSpec $spec): UiFieldValidationRuleInterface;

    /**
     * Sorted list of rule names this registry knows about. Used by the
     * parser to produce "unknown rule" error diagnostics. Order is the
     * registry's choice; the default registry returns built-ins in
     * declaration order.
     *
     * @return list<string>
     */
    public function knownRuleNames(): array;
}
