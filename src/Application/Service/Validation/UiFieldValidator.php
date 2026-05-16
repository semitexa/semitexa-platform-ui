<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Validation;

use Semitexa\PlatformUi\Domain\Model\Event\UiFieldValidationResult;

/**
 * Stateless first-failure-wins evaluator over a list of pre-resolved
 * UiFieldValidationRuleInterface objects.
 *
 * Caller responsibility: parse + resolve rules first (via
 * UiFieldRuleParser). The validator does not parse strings — it
 * works on already-resolved rule objects so the parsing security
 * perimeter stays in one place.
 *
 * Behavior:
 *   - With zero rules → returns valid() with no message (silent pass).
 *   - With ≥ 1 rule → runs in order; the first non-null return short-
 *     circuits as the failure result.
 *   - All rules return null → returns valid('Looks good.') by default;
 *     callers can override the success message.
 */
final class UiFieldValidator
{
    public const DEFAULT_SUCCESS_MESSAGE = 'Looks good.';

    /**
     * @param list<UiFieldValidationRuleInterface> $rules
     */
    public function validate(
        mixed $value,
        array $rules,
        UiFieldValidationContext $context,
        ?string $successMessage = self::DEFAULT_SUCCESS_MESSAGE,
    ): UiFieldValidationResult {
        foreach ($rules as $rule) {
            $result = $rule->validate($value, $context);
            if ($result !== null) {
                return $result;
            }
        }
        return UiFieldValidationResult::valid($successMessage);
    }
}
