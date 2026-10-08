<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Field\Type;

use Semitexa\PlatformUi\Domain\Model\Field\UiField;

/** References to several records of another model; the CRUD layer supplies the options. */
final class BelongsToManyFieldType extends AbstractUiFieldType
{
    public function name(): string
    {
        return 'belongsToMany';
    }

    public function description(): string
    {
        return 'References to several records of another model (a pivot), chosen with checkboxes or a multi-select.';
    }

    protected function controlProps(UiField $field): array
    {
        $options = array_map(static fn (array $o): array => ['value' => $o['value'], 'label' => $o['label']], $field->options);

        return count($options) > 8
            ? ['control' => 'select', 'multiple' => true, 'options' => $options]
            : ['control' => 'checkboxes', 'options' => $options];
    }

    protected function typeRules(UiField $field): array
    {
        $values = array_column($field->options, 'value');

        return $values === [] ? [] : [['in', ...$values]];
    }

    public function filter(UiField $field): ?array
    {
        // The choices, when the CRUD layer has supplied them, are the filter's select.
        $options = array_map(static fn (array $o): array => ['value' => $o['value'], 'label' => $o['label']], $field->options);

        return ['label' => $field->label, 'operators' => ['eq']] + ($options === [] ? [] : ['options' => $options]);
    }

    public function cast(UiField $field, mixed $raw): mixed
    {
        $values = is_array($raw) ? $raw : ($raw === null || $raw === '' ? [] : [$raw]);

        return array_values(array_unique(array_map('strval', array_filter($values, 'is_scalar'))));
    }
}
