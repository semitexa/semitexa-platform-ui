<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Field\Type;

use Semitexa\PlatformUi\Domain\Model\Field\UiColumnShape;
use Semitexa\PlatformUi\Domain\Model\Field\UiField;

/** A whole number; `min` / `max` bound it. */
final class IntegerFieldType extends AbstractUiFieldType
{
    public function name(): string
    {
        return 'integer';
    }

    public function description(): string
    {
        return 'A whole number. Settings: min, max.';
    }

    protected function controlProps(UiField $field): array
    {
        $input = ['type' => 'number', 'inputmode' => 'numeric', 'step' => 1];
        foreach (['min', 'max'] as $bound) {
            if (is_int($field->setting($bound))) {
                $input[$bound] = $field->setting($bound);
            }
        }

        return ['control' => 'input', 'inputProps' => $input];
    }

    protected function typeRules(UiField $field): array
    {
        $rules = ['integer'];
        foreach (['min', 'max'] as $bound) {
            if (is_int($field->setting($bound))) {
                $rules[] = [$bound, $field->setting($bound)];
            }
        }

        return $rules;
    }

    public function column(UiField $field): array
    {
        return ['field' => $field->name, 'label' => $field->label, 'format' => 'number'];
    }

    public function filter(UiField $field): ?array
    {
        // A range: the grid shows "from" and "to" inputs.
        return ['label' => $field->label, 'operators' => ['eq', 'in', 'gte', 'lte'], 'input' => 'number'];
    }

    public function cast(UiField $field, mixed $raw): mixed
    {
        $value = parent::cast($field, $raw);

        return is_string($value) && preg_match('/\A-?\d+\z/', $value) === 1 ? (int) $value : $value;
    }

    public function infers(UiColumnShape $column): int
    {
        return !$column->isPrimaryKey && in_array($column->type, ['int', 'integer', 'bigint', 'smallint', 'mediumint'], true) ? 60 : 0;
    }
}
