<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Field\Type;

use Semitexa\PlatformUi\Domain\Model\Field\UiColumnShape;
use Semitexa\PlatformUi\Domain\Model\Field\UiField;

/** A moment in time, in UTC end to end (the framework invariant); the grid formats it for the visitor's locale. */
final class DatetimeFieldType extends AbstractUiFieldType
{
    public function name(): string
    {
        return 'datetime';
    }

    public function description(): string
    {
        return 'A moment in time, entered and shown in UTC (formatted for the visitor\'s locale).';
    }

    /**
     * `datetime-local` carries no time zone. The value is read as UTC (the
     * framework's invariant) and the grid shows UTC too, so what a visitor types
     * is what they see; the field says so unless it has its own help.
     */
    protected function controlProps(UiField $field): array
    {
        $props = ['control' => 'input', 'inputProps' => ['type' => 'datetime-local']];
        if ($field->help === '') {
            $props['help'] = 'In UTC.';
        }

        return $props;
    }

    /** A crafted request is not a date picker: what cast() cannot read is refused, not stored as null. */
    protected function typeRules(UiField $field): array
    {
        return ['datetime'];
    }

    public function column(UiField $field): array
    {
        return ['field' => $field->name, 'label' => $field->label, 'format' => 'datetime'];
    }

    /**
     * Not filterable yet: equality on a moment is useless, and the collection
     * filter has no range operators (eq, in, contains) — tk-rs-range-filters.
     */
    /**
     * A range of days (UTC): "to" a day includes that whole day — the feed
     * reads a date-only upper end as its last second.
     */
    public function filter(UiField $field): ?array
    {
        return ['label' => $field->label, 'operators' => ['gte', 'lte'], 'input' => 'date'];
    }

    public function cast(UiField $field, mixed $raw): mixed
    {
        $value = parent::cast($field, $raw);
        if (!is_string($value)) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    public function infers(UiColumnShape $column): int
    {
        return in_array($column->type, ['datetime', 'timestamp', 'datetime_immutable'], true) ? 70 : 0;
    }

    public function fromColumn(UiColumnShape $column): UiField
    {
        $field = parent::fromColumn($column)->sortable();

        // Bookkeeping columns are the database's to write, not the visitor's.
        return self::nameSays($column, 'created', 'updated', 'deleted') ? $field->readOnly() : $field;
    }
}
