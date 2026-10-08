<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Validation\Rule;

use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationContext;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationRuleInterface;
use Semitexa\PlatformUi\Domain\Model\Event\UiFieldValidationResult;

/** A numeric lower bound. A value that is not a number is `number`'s business. */
final class MinRule implements UiFieldValidationRuleInterface
{
    public const NAME = 'min';

    public function __construct(private readonly int|float $min)
    {
    }

    public function validate(mixed $value, UiFieldValidationContext $context): ?UiFieldValidationResult
    {
        if (!is_numeric($value) || (float) $value >= $this->min) {
            return null;
        }

        return UiFieldValidationResult::invalid(sprintf('Please enter %s or more.', $this->min));
    }
}
