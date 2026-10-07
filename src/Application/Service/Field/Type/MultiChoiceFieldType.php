<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Field\Type;

use Semitexa\PlatformUi\Domain\Model\Field\UiField;

/** Several values from a closed list; every one must be a listed value. */
final class MultiChoiceFieldType extends AbstractUiFieldType
{
    public function name(): string
    {
        return 'multiChoice';
    }

    public function description(): string
    {
        return 'Several values from a closed list, edited with checkboxes (or a multi-select over 8 options).';
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
        $options = array_map(static fn (array $o): array => ['value' => $o['value'], 'label' => $o['label']], $field->options);

        return ['label' => $field->label, 'operators' => ['eq'], 'options' => $options];
    }

    public function cast(UiField $field, mixed $raw): mixed
    {
        $values = is_array($raw) ? $raw : ($raw === null || $raw === '' ? [] : [$raw]);

        return array_values(array_unique(array_map('strval', array_filter($values, 'is_scalar'))));
    }
}
