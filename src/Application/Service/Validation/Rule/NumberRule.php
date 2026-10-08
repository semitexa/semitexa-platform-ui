<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Validation\Rule;

use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationContext;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationRuleInterface;
use Semitexa\PlatformUi\Domain\Model\Event\UiFieldValidationResult;

/**
 * A decimal number, written with a dot ("12.5", "-3"); an optional cap on the
 * digits after the dot (`['number', 2]` for money). No exponent, no grouping.
 */
final class NumberRule implements UiFieldValidationRuleInterface
{
    public const NAME = 'number';

    public function __construct(private readonly ?int $scale = null)
    {
        if ($this->scale !== null && ($this->scale < 0 || $this->scale > 12)) {
            throw new \InvalidArgumentException('NumberRule scale must be 0–12.');
        }
    }

    public function validate(mixed $value, UiFieldValidationContext $context): ?UiFieldValidationResult
    {
        if (is_int($value)) {
            return null;
        }
        $text = is_scalar($value) ? trim((string) $value) : '';
        if ($text === '') {
            return null;
        }
        if (preg_match('/\A-?\d{1,18}(?:\.(\d{1,12}))?\z/', $text, $m) !== 1) {
            return UiFieldValidationResult::invalid('Please enter a number.');
        }
        if ($this->scale !== null && strlen($m[1] ?? '') > $this->scale) {
            return UiFieldValidationResult::invalid($this->scale === 0
                ? 'Please enter a whole number.'
                : sprintf('Please use at most %d digit(s) after the point.', $this->scale));
        }

        return null;
    }
}
