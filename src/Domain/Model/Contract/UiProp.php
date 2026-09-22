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
        if ($default !== null && (!$type->accepts($default) || ($values !== [] && !in_array($default, $values, true)))) {
            throw new InvalidArgumentException("Invalid default for UI prop {$name}.");
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
    }

    public function hasDefault(): bool
    {
        return $this->default !== null || ($this->nullable && !$this->required);
    }

    /**
     * @param list<UiProp> $props
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
            $schema['default'] = $this->default;
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
