<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Validation\Rule;

use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationContext;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationRuleInterface;
use Semitexa\PlatformUi\Domain\Model\Event\UiFieldValidationResult;

/** A numeric upper bound. A value that is not a number is `number`'s business. */
final class MaxRule implements UiFieldValidationRuleInterface
{
    public const NAME = 'max';

    public function __construct(private readonly int|float $max)
    {
    }

    public function validate(mixed $value, UiFieldValidationContext $context): ?UiFieldValidationResult
    {
        if (!is_numeric($value) || (float) $value <= $this->max) {
            return null;
        }

        return UiFieldValidationResult::invalid(sprintf('Please enter %s or less.', $this->max));
    }
}
