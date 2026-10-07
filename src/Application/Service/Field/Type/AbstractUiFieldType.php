<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Field\Type;

use Semitexa\PlatformUi\Domain\Contract\UiFieldTypeInterface;
use Semitexa\PlatformUi\Domain\Model\Field\UiColumnShape;
use Semitexa\PlatformUi\Domain\Model\Field\UiField;

/**
 * What most types share: a labelled `platform.field` that validates as it is
 * edited, `required` from the field, a text column, equality filtering, a
 * trimmed string (null when empty) as the stored value, and no inference.
 */
abstract class AbstractUiFieldType implements UiFieldTypeInterface
{
    public function formProps(UiField $field): array
    {
        $props = [
            'label' => $field->label,
            'name' => $field->name,
            'required' => $field->required,
            'rules' => $this->rules($field),
            'showValidationTarget' => true,
        ];
        if ($field->help !== '') {
            $props['help'] = $field->help;
        }
        if ($field->placeholder !== '') {
            $props['placeholder'] = $field->placeholder;
        }

        return $props + $this->controlProps($field);
    }

    /** @return array<string, mixed> the control-specific part of formProps() */
    protected function controlProps(UiField $field): array
    {
        return ['control' => 'input', 'inputProps' => ['type' => 'text']];
    }

    public function rules(UiField $field): array
    {
        $rules = $field->required ? ['required'] : [];

        return [...$rules, ...$this->typeRules($field), ...$field->rules];
    }

    /** @return list<string|array<int, scalar>> */
    protected function typeRules(UiField $field): array
    {
        return [];
    }

    public function column(UiField $field): array
    {
        return ['field' => $field->name, 'label' => $field->label, 'format' => 'text'];
    }

    public function filter(UiField $field): ?array
    {
        return ['label' => $field->label, 'operators' => ['eq']];
    }

    public function cast(UiField $field, mixed $raw): mixed
    {
        if (!is_scalar($raw)) {
            return null;
        }
        $text = trim((string) $raw);

        return $text === '' ? null : $text;
    }

    public function infers(UiColumnShape $column): int
    {
        return 0;
    }

    public function fromColumn(UiColumnShape $column): UiField
    {
        $field = new UiField($column->name, $this->name());

        return $column->nullable ? $field : $field->required();
    }

    /** True when the column's name says it holds $what ("email", "contact_email", "emailAddress"). */
    protected static function nameSays(UiColumnShape $column, string ...$words): bool
    {
        $name = strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $column->name));
        foreach ($words as $word) {
            if (preg_match('/(\A|_)' . preg_quote($word, '/') . '(_|\z)/', $name) === 1) {
                return true;
            }
        }

        return false;
    }
}
