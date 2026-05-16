<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Validation\Rule;

use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationContext;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationRuleInterface;
use Semitexa\PlatformUi\Domain\Model\Event\UiFieldValidationResult;

/**
 * Rule id: `minLength`. One integer parameter: minimum mb-character count.
 *
 * Trims first, then compares against `mb_strlen` so unicode characters
 * count as 1. Empty strings pass — pair with `required` if a non-empty
 * value is also required. Passing 0 makes the rule a no-op (everything
 * passes); the parser does not reject this so callers can build rule
 * lists programmatically.
 */
final class MinLengthRule implements UiFieldValidationRuleInterface
{
    public const NAME = 'minLength';

    public function __construct(private readonly int $min)
    {
        if ($this->min < 0) {
            throw new \InvalidArgumentException('MinLengthRule requires a non-negative minimum.');
        }
    }

    public function validate(mixed $value, UiFieldValidationContext $context): ?UiFieldValidationResult
    {
        $stringValue = is_scalar($value) ? (string) $value : '';
        $trimmed = trim($stringValue);
        if ($trimmed === '') {
            // Empty values pass; pair with `required` to reject empties.
            return null;
        }
        if (mb_strlen($trimmed) < $this->min) {
            return UiFieldValidationResult::invalid(sprintf(
                'Please enter at least %d characters.',
                $this->min,
            ));
        }
        return null;
    }
}
