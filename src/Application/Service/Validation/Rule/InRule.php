<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Validation\Rule;

use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationContext;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationRuleInterface;
use Semitexa\PlatformUi\Domain\Model\Event\UiFieldValidationResult;

/**
 * The value is one of the listed ones — or, for a multi-value field
 * (checkboxes, a multi-select), every one of its values is. What a select
 * shows is not a promise: a crafted request can send anything.
 */
final class InRule implements UiFieldValidationRuleInterface
{
    public const NAME = 'in';

    /** @var array<string, true> */
    private readonly array $allowed;

    /** @param list<string|int> $values */
    public function __construct(array $values)
    {
        if ($values === []) {
            throw new \InvalidArgumentException('InRule needs at least one allowed value.');
        }
        $this->allowed = array_fill_keys(array_map('strval', $values), true);
    }

    public function validate(mixed $value, UiFieldValidationContext $context): ?UiFieldValidationResult
    {
        // A list control's values one by one; its joined string would read
        // "a,b" as one unknown value.
        $values = $context->submittedList ?? (is_array($value) ? $value : [$value]);
        foreach ($values as $one) {
            if (!is_scalar($one)) {
                return UiFieldValidationResult::invalid('Please choose from the list.');
            }
            $text = (string) $one;
            if ($text !== '' && !isset($this->allowed[$text])) {
                return UiFieldValidationResult::invalid('Please choose from the list.');
            }
        }

        return null;
    }
}
