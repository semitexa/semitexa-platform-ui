<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Field\Type;

use Semitexa\PlatformUi\Domain\Model\Field\UiField;

/**
 * One value from a closed list. The `in` rule refuses anything else — a select
 * shows the list, a crafted request can send anything. The column is a badge
 * coloured by each option's tone.
 */
final class ChoiceFieldType extends AbstractUiFieldType
{
    public function name(): string
    {
        return 'choice';
    }

    public function description(): string
    {
        return 'One value from a closed list, shown as a badge (options may carry a tone). Settings: control (select | radio | segmented).';
    }

    protected function controlProps(UiField $field): array
    {
        $control = (string) $field->setting('control', count($field->options) <= 4 ? 'segmented' : 'select');
        $control = in_array($control, ['select', 'radio', 'segmented'], true) ? $control : 'select';
        $props = ['control' => $control, 'options' => self::plainOptions($field)];
        // An optional choice in a list can be cleared (radios and segments cannot, by nature).
        if ($control === 'select' && !$field->required && $field->placeholder === '') {
            $props['placeholder'] = '— None —';
        }

        return $props;
    }

    protected function typeRules(UiField $field): array
    {
        $values = array_column($field->options, 'value');

        return $values === [] ? [] : [['in', ...$values]];
    }

    public function column(UiField $field): array
    {
        $variants = [];
        $labels = [];
        foreach ($field->options as $option) {
            $variants[$option['value']] = $option['tone'] ?? 'neutral';
            $labels[$option['value']] = $option['label'];
        }

        // The badge shows the option's label ("Draft"), not the stored value.
        return ['field' => $field->name, 'label' => $field->label, 'format' => 'badge', 'variants' => $variants, 'labels' => $labels];
    }

    public function filter(UiField $field): ?array
    {
        return ['label' => $field->label, 'operators' => ['eq'], 'options' => self::plainOptions($field)];
    }

    /** @return list<array{value: string, label: string}> */
    private static function plainOptions(UiField $field): array
    {
        return array_map(static fn (array $o): array => ['value' => $o['value'], 'label' => $o['label']], $field->options);
    }
}
