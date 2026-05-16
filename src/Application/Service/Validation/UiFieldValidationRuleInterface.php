<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Validation;

use Semitexa\PlatformUi\Domain\Model\Event\UiFieldValidationResult;

/**
 * One validation step against a single field value.
 *
 * Contract:
 *   - Returns `null` to mean "this rule passes; defer to the next rule".
 *   - Returns a `UiFieldValidationResult::invalid(...)` to short-circuit
 *     the pipeline with a diagnostic. First failure wins.
 *
 * Implementations MUST be pure: no IO, no globals, no Twig, no
 * services. The Platform UI validator stays sync + stateless on
 * purpose — async / cross-field rules are a separate future slice.
 *
 * Built-in rules live under
 * Semitexa\PlatformUi\Application\Service\Validation\Rule\*.
 * App-specific custom rules are explicitly out of scope for this slice
 * (the parser only resolves names from the built-in map); a custom-rule
 * registry can be added in a follow-up without changing this contract.
 */
interface UiFieldValidationRuleInterface
{
    public function validate(mixed $value, UiFieldValidationContext $context): ?UiFieldValidationResult;
}
