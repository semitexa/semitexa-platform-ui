<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Validation\Rule;

use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationContext;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationRuleInterface;
use Semitexa\PlatformUi\Domain\Model\Event\UiFieldValidationResult;

/**
 * Rule id: `required`. No parameters.
 *
 * Trims the incoming value, then fails when the trimmed string is
 * empty. Matches the behavior FieldComponent's hardcoded validation
 * shipped in the previous slice.
 */
final class RequiredRule implements UiFieldValidationRuleInterface
{
    public const NAME = 'required';
    public const MESSAGE = 'This field is required.';

    public function validate(mixed $value, UiFieldValidationContext $context): ?UiFieldValidationResult
    {
        $stringValue = is_scalar($value) ? (string) $value : '';
        if (trim($stringValue) === '') {
            return UiFieldValidationResult::invalid(self::MESSAGE);
        }
        return null;
    }
}
