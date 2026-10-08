<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Validation\Rule;

use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationContext;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationRuleInterface;
use Semitexa\PlatformUi\Domain\Model\Event\UiFieldValidationResult;

/**
 * An absolute http(s) URL. Other schemes are refused: a stored URL is later
 * rendered as a link, and `javascript:` passes FILTER_VALIDATE_URL.
 */
final class UrlRule implements UiFieldValidationRuleInterface
{
    public const NAME = 'url';

    public function validate(mixed $value, UiFieldValidationContext $context): ?UiFieldValidationResult
    {
        $text = is_scalar($value) ? trim((string) $value) : '';
        if ($text === '') {
            return null;
        }
        $scheme = strtolower((string) parse_url($text, PHP_URL_SCHEME));
        if (filter_var($text, FILTER_VALIDATE_URL) !== false && in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return UiFieldValidationResult::invalid('Please enter a full web address (https://…).');
    }
}
