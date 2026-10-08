<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Validation\Rule;

use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationContext;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationRuleInterface;
use Semitexa\PlatformUi\Domain\Model\Event\UiFieldValidationResult;

/** An email address (FILTER_VALIDATE_EMAIL). An empty value is `required`'s business. */
final class EmailRule implements UiFieldValidationRuleInterface
{
    public const NAME = 'email';

    public function validate(mixed $value, UiFieldValidationContext $context): ?UiFieldValidationResult
    {
        $text = is_scalar($value) ? trim((string) $value) : '';
        if ($text === '' || filter_var($text, FILTER_VALIDATE_EMAIL) !== false) {
            return null;
        }

        return UiFieldValidationResult::invalid('Please enter a valid email address.');
    }
}
