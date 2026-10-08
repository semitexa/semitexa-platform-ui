<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Validation;

/**
 * A submitted control value as the string the field rules validate.
 *
 * Text controls send a string; a checkbox sends a boolean; a multi-select or
 * checkbox group sends a list. `required` must hold for all of them, so an
 * unchecked box and an empty list both read as '' (missing) and a list reads
 * as its values joined.
 */
final class UiFieldValue
{
    public static function asString(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '';
        }
        if (is_array($value)) {
            return implode(',', array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $value));
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
