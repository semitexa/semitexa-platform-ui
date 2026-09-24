<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Contract;

use InvalidArgumentException;

/** A prop declaration shared by JSON Schema, controls and validation. */
final readonly class UiProp
{
    /**
     * @param list<string|int|float|bool> $values
     * @param list<UiProp> $properties Nested object properties; empty means an open map.
     */
    public function __construct(
        public string $name,
        public UiPropType $type = UiPropType::String,
        public mixed $default = null,
        public bool $required = false,
        public bool $nullable = false,
        public array $values = [],
        public string $description = '',
        public ?self $items = null,
        public array $properties = [],
        public bool $sensitive = false,
    ) {
        if (preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]*\z/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid UI prop name: {$name}");
        }
        if ($required && $default !== null) {
            throw new InvalidArgumentException("Required UI prop {$name} cannot also have a default.");
        }
        foreach ($values as $value) {
            if (!$type->accepts($value)) {
                throw new InvalidArgumentException("Invalid enum value for UI prop {$name}.");
            }
        }
        if (($items !== null && $type !== UiPropType::Array) || ($properties !== [] && $type !== UiPropType::Object)) {
            throw new InvalidArgumentException("Nested schema does not match UI prop {$name}.");
        }
        self::index($properties);
        if ($default !== null) {
            try {
                $this->validate($default, $name);
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException("Invalid default for UI prop {$name}: {$e->getMessage()}", 0, $e);
            }
        }
    }

    /**
     * Checks a value against this declaration the way its schema() would:
     * type, enum, nullability, and nested items/properties.
     *
     * @throws InvalidArgumentException naming the offending path
     */
    public function validate(mixed $value, string $path): void
    {
        if ($value === null) {
            if (!$this->nullable) {
                throw new InvalidArgumentException("{$path} is not nullable.");
            }
            return;
        }
        if (!$this->type->accepts($value)) {
            throw new InvalidArgumentException("{$path} must be of type {$this->type->value}.");
        }
        if ($this->values !== [] && !$this->type->inEnum($value, $this->values)) {
            throw new InvalidArgumentException("{$path} is not one of the declared values.");
        }
        if ($this->items !== null && is_array($value)) {
            foreach ($value as $index => $item) {
                $this->items->validate($item, "{$path}[{$index}]");
            }
        }
        if ($this->type === UiPropType::Object && $this->properties !== [] && is_array($value)) {
            self::validateObject($this->properties, $value, $path);
        }
    }

    /**
     * An object value against a closed property list — objectSchema() emits
     * additionalProperties: false, so an unknown key is an error, not extra.
     *
     * @param list<UiProp> $props
     * @param array<array-key, mixed> $value
     */
    public static function validateObject(array $props, array $value, string $path): void
    {
        $indexed = self::index($props);
        foreach ($value as $key => $item) {
            if (!isset($indexed[$key])) {
                throw new InvalidArgumentException("{$path} has no prop named {$key}.");
            }
            $indexed[$key]->validate($item, "{$path}.{$key}");
        }
        foreach ($indexed as $name => $prop) {
            if ($prop->required && !array_key_exists($name, $value)) {
                throw new InvalidArgumentException("{$path} is missing required prop {$name}.");
            }
        }
    }

    /**
     * The value as JSON should see it. PHP has one array type, so an empty
     * object-typed value would otherwise encode as `[]` under `type: object`.
     */
    public function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if ($this->type === UiPropType::Object) {
            return self::normalizeObject($this->properties, $value);
        }
        if ($this->items !== null) {
            return array_map($this->items->normalize(...), $value);
        }
        return $value;
    }

    /**
     * @param list<UiProp> $props
     * @param array<array-key, mixed> $value
     */
    public static function normalizeObject(array $props, array $value): object
    {
        $indexed = self::index($props);
        foreach ($value as $key => $item) {
            if (isset($indexed[$key])) {
                $value[$key] = $indexed[$key]->normalize($item);
            }
        }
        return (object) $value;
    }

    public function hasDefault(): bool
    {
        return $this->default !== null || ($this->nullable && !$this->required);
    }

    /**
     * @param array<mixed> $props attribute arguments; each element is checked
     * @return array<string, UiProp>
     */
    public static function index(array $props): array
    {
        $result = [];
        foreach ($props as $prop) {
            if (!$prop instanceof self || isset($result[$prop->name])) {
                throw new InvalidArgumentException('UI props must be unique UiProp declarations.');
            }
            $result[$prop->name] = $prop;
        }
        return $result;
    }

    /** @return array<string, mixed> */
    public function schema(): array
    {
        $schema = ['type' => $this->nullable ? [$this->type->value, 'null'] : $this->type->value];
        if ($this->description !== '') {
            $schema['description'] = $this->description;
        }
        if ($this->hasDefault()) {
            $schema['default'] = $this->normalize($this->default);
        }
        if ($this->values !== []) {
            $schema['enum'] = $this->nullable ? [...$this->values, null] : $this->values;
        }
        if ($this->items !== null) {
            $schema['items'] = $this->items->schema();
        }
        if ($this->type === UiPropType::Object && $this->properties !== []) {
            $schema += self::objectSchema($this->properties);
        }
        if ($this->sensitive) {
            $schema['writeOnly'] = true;
        }
        return $schema;
    }

    /**
     * @param list<UiProp> $props
     * @return array<string, mixed>
     */
    public static function objectSchema(array $props): array
    {
        $indexed = self::index($props);
        return [
            'type' => 'object',
            'properties' => (object) array_map(static fn (self $p): array => $p->schema(), $indexed),
            'required' => array_keys(array_filter($indexed, static fn (self $p): bool => $p->required)),
            'additionalProperties' => false,
        ];
    }
}
