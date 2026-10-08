<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Field\Type;

use Semitexa\PlatformUi\Domain\Model\Field\UiColumnShape;
use Semitexa\PlatformUi\Domain\Model\Field\UiField;

/** Yes / no: a switch on the form, a check mark in the grid, a yes/no filter. */
final class BooleanFieldType extends AbstractUiFieldType
{
    public function name(): string
    {
        return 'boolean';
    }

    public function description(): string
    {
        return 'Yes or no, edited with a switch.';
    }

    /** The label sits beside the switch (its accessible name), not above it as well. */
    public function formProps(UiField $field): array
    {
        $props = parent::formProps($field);
        unset($props['label']);

        return $props;
    }

    protected function controlProps(UiField $field): array
    {
        return ['control' => 'switch', 'checkboxLabel' => $field->label];
    }

    public function rules(UiField $field): array
    {
        // `required` on a switch would mean "must be on" — a different rule from
        // "must be answered", which a switch always is. Use rule('required') for that.
        return [...$this->typeRules($field), ...$field->rules];
    }

    public function column(UiField $field): array
    {
        return ['field' => $field->name, 'label' => $field->label, 'format' => 'boolean'];
    }

    public function filter(UiField $field): ?array
    {
        return ['label' => $field->label, 'operators' => ['eq'], 'options' => [['value' => '1', 'label' => 'Yes'], ['value' => '0', 'label' => 'No']]];
    }

    public function cast(UiField $field, mixed $raw): mixed
    {
        return in_array($raw, [true, 1, '1', 'on', 'true', 'yes'], true);
    }

    public function infers(UiColumnShape $column): int
    {
        if (in_array($column->type, ['boolean', 'bool'], true)) {
            return 70;
        }

        return $column->type === 'tinyint' && $column->length === 1 ? 70 : 0;
    }

    public function fromColumn(UiColumnShape $column): UiField
    {
        return new UiField($column->name, $this->name());
    }
}
