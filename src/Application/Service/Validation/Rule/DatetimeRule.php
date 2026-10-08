<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Validation\Rule;

use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationContext;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationRuleInterface;
use Semitexa\PlatformUi\Domain\Model\Event\UiFieldValidationResult;

/**
 * A moment as a datetime-local input sends it: "2026-10-08T14:30", with
 * optional seconds and fraction. Relative words ("now", "+1 day") and days
 * that do not exist are refused. An empty value is `required`'s business.
 */
final class DatetimeRule implements UiFieldValidationRuleInterface
{
    public const NAME = 'datetime';

    public function validate(mixed $value, UiFieldValidationContext $context): ?UiFieldValidationResult
    {
        $text = is_scalar($value) ? trim((string) $value) : '';
        if ($text === '' || self::isDatetime($text)) {
            return null;
        }

        return UiFieldValidationResult::invalid('Please enter a valid date and time.');
    }

    public static function isDatetime(string $text): bool
    {
        if (preg_match('/\A(\d{4}-\d{2}-\d{2})[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,6})?)?\z/', $text, $m) !== 1) {
            return false;
        }

        return DateRule::isDate($m[1]) && (int) $m[2] < 24 && (int) $m[3] < 60 && (int) ($m[4] ?? 0) < 60;
    }
}
