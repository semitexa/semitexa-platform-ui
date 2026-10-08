<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Field;

/**
 * One field of a form, a grid and a filter at once: a name, a type from the
 * field-type registry, and what differs from that type's defaults.
 *
 * Immutable; every setter returns a copy. `Field::text('title')->required()`
 * reads as the declaration it is, and `$field->required` reads the result. What a type does with these values (the
 * control it renders, the column it shows, the rules it checks) is the type's
 * business — see UiFieldTypeInterface.
 */
final class UiField
{
    public const NAME_PATTERN = '/\A[a-zA-Z][a-zA-Z0-9_]*\z/';

    // Readable everywhere, written only through the copy-returning setters below.
    public private(set) string $label;
    public private(set) bool $required = false;
    public private(set) bool $readOnly = false;
    public private(set) bool $sortable = false;
    public private(set) bool $searchable = false;
    public private(set) bool $filterable = false;
    public private(set) bool $onList = true;
    public private(set) bool $onForm = true;
    public private(set) string $help = '';
    public private(set) string $placeholder = '';
    public private(set) mixed $default = null;
    /** @var list<array{value: string, label: string, tone?: string}> */
    public private(set) array $options = [];
    /** @var list<string|array{0: string, 1?: scalar}> */
    public private(set) array $rules = [];
    /** @var array<string, mixed> type-specific settings (max, min, scale, from, accept, …) */
    private array $settings = [];

    public function __construct(public readonly string $name, public readonly string $type)
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a field name ([a-zA-Z][a-zA-Z0-9_]*).', $name));
        }
        if (preg_match('/\A[a-z][a-zA-Z0-9]*\z/', $type) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a field type name.', $type));
        }
        $this->label = self::humanize($name);
    }

    public function label(string $label): self { return $this->with('label', $label); }
    public function required(bool $required = true): self { return $this->with('required', $required); }
    public function readOnly(bool $readOnly = true): self { return $this->with('readOnly', $readOnly); }
    public function sortable(bool $sortable = true): self { return $this->with('sortable', $sortable); }
    public function searchable(bool $searchable = true): self { return $this->with('searchable', $searchable); }
    public function filterable(bool $filterable = true): self { return $this->with('filterable', $filterable); }
    public function hideOnList(): self { return $this->with('onList', false); }
    public function hideOnForm(): self { return $this->with('onForm', false); }
    public function help(string $help): self { return $this->with('help', $help); }
    public function placeholder(string $placeholder): self { return $this->with('placeholder', $placeholder); }
    public function default(mixed $value): self { return $this->with('default', $value); }

    /** An extra validation rule on top of the type's own: 'email', ['min', 1]. */
    public function rule(string|array $rule): self
    {
        $copy = clone $this;
        $copy->rules[] = $rule;

        return $copy;
    }

    /**
     * The choices of a choice / relation field, as value => label (or a list of
     * values, labelled by humanizing). A tone colours the value's badge:
     * ['published' => ['label' => 'Published', 'tone' => 'success']].
     * Only a real list (keys 0, 1, 2…) is read as values: [1 => 'Bad', 2 => 'Good']
     * is the values 1 and 2, labelled — a numeric key is a value too.
     *
     * @param array<string|int, string|array{label?: string, tone?: string}> $options
     */
    public function options(array $options): self
    {
        $normalized = [];
        $isList = array_is_list($options);
        foreach ($options as $key => $option) {
            if ($isList && is_string($option)) {
                $normalized[] = ['value' => $option, 'label' => self::humanize($option)];
                continue;
            }
            $entry = ['value' => (string) $key, 'label' => is_array($option) ? (string) ($option['label'] ?? self::humanize((string) $key)) : (string) $option];
            if (is_array($option) && isset($option['tone'])) {
                $entry['tone'] = (string) $option['tone'];
            }
            $normalized[] = $entry;
        }

        return $this->with('options', $normalized);
    }

    /** A type-specific setting (the type documents which it reads). */
    public function set(string $key, mixed $value): self
    {
        $copy = clone $this;
        $copy->settings[$key] = $value;

        return $copy;
    }

    /** On the create / edit form: declared so, and not read-only. */
    public function isOnForm(): bool { return $this->onForm && !$this->readOnly; }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /** "updatedAt" → "Updated at", "category_id" → "Category id". */
    public static function humanize(string $name): string
    {
        $spaced = strtolower(trim((string) preg_replace(['/([a-z0-9])([A-Z])/', '/[_-]+/'], ['$1 $2', ' '], $name)));

        return ucfirst($spaced);
    }

    private function with(string $property, mixed $value): self
    {
        $copy = clone $this;
        $copy->{$property} = $value;

        return $copy;
    }
}
