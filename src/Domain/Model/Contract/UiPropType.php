<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Contract;

enum UiPropType: string
{
    case String = 'string';
    case Boolean = 'boolean';
    case Integer = 'integer';
    case Number = 'number';
    case Array = 'array';
    case Object = 'object';

    public function accepts(mixed $value): bool
    {
        return match ($this) {
            self::String => is_string($value),
            self::Boolean => is_bool($value),
            // JSON Schema: an integer is any number with a zero fractional part, 3.0 included.
            self::Integer => is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value),
            self::Number => is_int($value) || (is_float($value) && is_finite($value)),
            self::Array => is_array($value) && array_is_list($value),
            self::Object => is_array($value) && ($value === [] || !array_is_list($value)),
        };
    }

    /**
     * Enum membership as JSON Schema defines it: numbers compare by value
     * (1 and 1.0 are the same instance), everything else strictly.
     *
     * @param list<string|int|float|bool> $values
     */
    public function inEnum(mixed $value, array $values): bool
    {
        if (!is_int($value) && !is_float($value)) {
            return in_array($value, $values, true);
        }
        foreach ($values as $candidate) {
            if ((is_int($candidate) || is_float($candidate)) && (float) $candidate === (float) $value) {
                return true;
            }
        }
        return false;
    }
}
