<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Validation\Rule;

use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationContext;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationRuleInterface;
use Semitexa\PlatformUi\Domain\Model\Event\UiFieldValidationResult;

/** A whole number, optionally negative. An empty value is `required`'s business. */
final class IntegerRule implements UiFieldValidationRuleInterface
{
    public const NAME = 'integer';

    public function validate(mixed $value, UiFieldValidationContext $context): ?UiFieldValidationResult
    {
        if (is_int($value)) {
            return null;
        }
        $text = is_scalar($value) ? trim((string) $value) : '';
        if ($text === '' || preg_match('/\A-?\d{1,18}\z/', $text) === 1) {
            return null;
        }

        return UiFieldValidationResult::invalid('Please enter a whole number.');
    }
}
