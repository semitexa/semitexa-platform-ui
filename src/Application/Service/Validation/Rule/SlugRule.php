<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Validation\Rule;

use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationContext;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationRuleInterface;
use Semitexa\PlatformUi\Domain\Model\Event\UiFieldValidationResult;

/** Lowercase letters and digits in hyphen-separated words: "release-notes-2026". */
final class SlugRule implements UiFieldValidationRuleInterface
{
    public const NAME = 'slug';

    public function validate(mixed $value, UiFieldValidationContext $context): ?UiFieldValidationResult
    {
        $text = is_scalar($value) ? (string) $value : '';
        if ($text === '' || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $text) === 1) {
            return null;
        }

        return UiFieldValidationResult::invalid('Use lowercase letters, digits and single hyphens.');
    }
}
