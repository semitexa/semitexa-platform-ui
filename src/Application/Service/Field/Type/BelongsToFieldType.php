<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Field\Type;

use Semitexa\PlatformUi\Domain\Model\Field\UiColumnShape;
use Semitexa\PlatformUi\Domain\Model\Field\UiField;

/**
 * A reference to one record of another model. The type knows the shape; the
 * records to choose from are data, so the CRUD layer supplies them as options.
 */
final class BelongsToFieldType extends AbstractUiFieldType
{
    public function name(): string
    {
        return 'belongsTo';
    }

    public function description(): string
    {
        return 'A reference to one record of another model, chosen from a list; the list is supplied by the CRUD layer.';
    }

    protected function controlProps(UiField $field): array
    {
        $props = ['control' => 'select', 'options' => array_map(static fn (array $o): array => ['value' => $o['value'], 'label' => $o['label']], $field->options)];
        // An optional reference can be cleared: the list starts with an empty choice.
        if (!$field->required && $field->placeholder === '') {
            $props['placeholder'] = '— None —';
        }

        return $props;
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

    /**
     * Never from the column alone: "external_id" is not a relation. The CRUD
     * layer reads the model's #[BelongsTo] and builds this field from it.
     */
    public function infers(UiColumnShape $column): int
    {
        return 0;
    }

    public function fromColumn(UiColumnShape $column): UiField
    {
        $field = parent::fromColumn($column);

        return $field->label(UiField::humanize((string) preg_replace('/(_id|Id)\z/', '', $column->name)));
    }
}
