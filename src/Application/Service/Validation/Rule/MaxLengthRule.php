<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Validation\Rule;

use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationContext;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationRuleInterface;
use Semitexa\PlatformUi\Domain\Model\Event\UiFieldValidationResult;

/**
 * Rule id: `maxLength`. One integer parameter: maximum mb-character count.
 *
 * Does NOT trim — leading/trailing whitespace counts because the
 * caller's intent for maxLength is usually "fits in a column / fits
 * in a UI control". Pair with `required` if empty must also be
 * rejected (maxLength alone passes empty values).
 */
final class MaxLengthRule implements UiFieldValidationRuleInterface
{
    public const NAME = 'maxLength';

    public function __construct(private readonly int $max)
    {
        if ($this->max < 0) {
            throw new \InvalidArgumentException('MaxLengthRule requires a non-negative maximum.');
        }
    }

    public function validate(mixed $value, UiFieldValidationContext $context): ?UiFieldValidationResult
    {
        $stringValue = is_scalar($value) ? (string) $value : '';
        if (mb_strlen($stringValue) > $this->max) {
            return UiFieldValidationResult::invalid(sprintf(
                'Please enter no more than %d characters.',
                $this->max,
            ));
        }
        return null;
    }
}
