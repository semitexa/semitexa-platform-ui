<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Validation\Rule;

use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationContext;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationRuleInterface;
use Semitexa\PlatformUi\Domain\Model\Event\UiFieldValidationResult;

/**
 * A calendar day as a date input sends it: "2026-10-08". A day that does not
 * exist ("2026-02-30") is refused, not rolled over into March. An empty value
 * is `required`'s business.
 */
final class DateRule implements UiFieldValidationRuleInterface
{
    public const NAME = 'date';

    public function validate(mixed $value, UiFieldValidationContext $context): ?UiFieldValidationResult
    {
        $text = is_scalar($value) ? trim((string) $value) : '';
        if ($text === '' || self::isDate($text)) {
            return null;
        }

        return UiFieldValidationResult::invalid('Please enter a valid date.');
    }

    public static function isDate(string $text): bool
    {
        return preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $text, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
