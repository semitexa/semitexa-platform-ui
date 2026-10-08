<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Field\Type;

use Semitexa\PlatformUi\Domain\Model\Field\UiColumnShape;
use Semitexa\PlatformUi\Domain\Model\Field\UiField;

/** A calendar date, stored as Y-m-d; the column is formatted in the visitor's locale. */
final class DateFieldType extends AbstractUiFieldType
{
    public function name(): string
    {
        return 'date';
    }

    public function description(): string
    {
        return 'A calendar date (no time).';
    }

    protected function controlProps(UiField $field): array
    {
        return ['control' => 'input', 'inputProps' => ['type' => 'date']];
    }

    /** A crafted request is not a date picker: what cast() cannot read is refused, not stored as null. */
    protected function typeRules(UiField $field): array
    {
        return ['date'];
    }

    public function column(UiField $field): array
    {
        return ['field' => $field->name, 'label' => $field->label, 'format' => 'date'];
    }

    public function filter(UiField $field): ?array
    {
        return ['label' => $field->label, 'operators' => ['eq', 'gte', 'lte'], 'input' => 'date'];
    }

    public function cast(UiField $field, mixed $raw): mixed
    {
        $value = parent::cast($field, $raw);
        if (!is_string($value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    public function infers(UiColumnShape $column): int
    {
        return $column->type === 'date' ? 70 : 0;
    }
}
