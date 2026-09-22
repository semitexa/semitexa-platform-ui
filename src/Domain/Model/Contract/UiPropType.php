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
            self::Integer => is_int($value),
            self::Number => is_int($value) || (is_float($value) && is_finite($value)),
            self::Array => is_array($value) && array_is_list($value),
            self::Object => is_array($value) && ($value === [] || !array_is_list($value)),
        };
    }
}
