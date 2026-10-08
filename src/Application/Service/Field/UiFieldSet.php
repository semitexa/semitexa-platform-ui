<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Field;

use Semitexa\PlatformUi\Domain\Model\Field\UiField;

/**
 * An ordered set of fields — one screen's worth — and the views of it a grid
 * and a form need. The route contract's `ui` block comes from here, so the
 * grid stops guessing columns from field names.
 */
final class UiFieldSet
{
    /** @var array<string, UiField> */
    private array $fields = [];

    /** @param iterable<UiField> $fields */
    public function __construct(iterable $fields)
    {
        foreach ($fields as $field) {
            if (isset($this->fields[$field->name])) {
                throw new \InvalidArgumentException(sprintf('Field "%s" is declared twice.', $field->name));
            }
            UiFieldTypes::for($field); // an unknown type fails here, at declaration
            $this->fields[$field->name] = $field;
        }
    }

    /** @return list<UiField> */
    public function all(): array
    {
        return array_values($this->fields);
    }

    public function get(string $name): ?UiField
    {
        return $this->fields[$name] ?? null;
    }

    /** @return list<UiField> */
    public function onList(): array
    {
        return array_values(array_filter($this->fields, static fn (UiField $f): bool => $f->onList));
    }

    /** @return list<UiField> */
    public function onForm(): array
    {
        return array_values(array_filter($this->fields, static fn (UiField $f): bool => $f->isOnForm()));
    }

    /**
     * The route contract's `ui` block: columns in declaration order, and a
     * filter overlay (label, options) for every filterable field.
     *
     * @return array{columns: list<array<string, mixed>>, filters: array<string, array<string, mixed>>}
     */
    public function contractUi(): array
    {
        $columns = [];
        foreach ($this->onList() as $field) {
            $columns[] = UiFieldTypes::for($field)->column($field);
        }
        $filters = [];
        foreach ($this->fields as $field) {
            if (!$field->filterable) {
                continue;
            }
            $filter = UiFieldTypes::for($field)->filter($field);
            if ($filter !== null) {
                $filters[$field->name] = $filter;
            }
        }

        return ['columns' => $columns, 'filters' => $filters];
    }

    /**
     * Submitted form values, cast to what is stored: every field the form
     * shows and may write (on the form, not read-only), and nothing else.
     *
     * @param array<array-key, mixed> $submitted
     * @return array<string, mixed>
     */
    public function cast(array $submitted): array
    {
        $values = [];
        foreach ($this->onForm() as $field) {
            if (!$field->readOnly) {
                $values[$field->name] = UiFieldTypes::for($field)->cast($field, $submitted[$field->name] ?? null);
            }
        }

        return $values;
    }

    /** The field that identifies a row: the first `id` field, else one named "id". */
    public function idField(): ?string
    {
        foreach ($this->fields as $field) {
            if ($field->type === 'id') {
                return $field->name;
            }
        }

        return isset($this->fields['id']) ? 'id' : null;
    }

    /** @return list<string> */
    public function sortable(): array
    {
        return array_keys(array_filter($this->fields, static fn (UiField $f): bool => $f->sortable));
    }

    /** @return list<string> */
    public function searchable(): array
    {
        return array_keys(array_filter($this->fields, static fn (UiField $f): bool => $f->searchable));
    }
}
