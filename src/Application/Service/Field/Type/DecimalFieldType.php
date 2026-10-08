<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Field\Type;

use Semitexa\PlatformUi\Domain\Model\Field\UiColumnShape;
use Semitexa\PlatformUi\Domain\Model\Field\UiField;

/** A decimal with `scale` places; kept as a string so no float ever rounds it. */
final class DecimalFieldType extends AbstractUiFieldType
{
    public function name(): string
    {
        return 'decimal';
    }

    public function description(): string
    {
        return 'A decimal number with a fixed number of places (money, measures). Settings: scale, min, max.';
    }

    protected function controlProps(UiField $field): array
    {
        $scale = (int) $field->setting('scale', 2);
        $input = ['type' => 'number', 'inputmode' => 'decimal', 'step' => $scale > 0 ? '0.' . str_repeat('0', $scale - 1) . '1' : 1];
        foreach (['min', 'max'] as $bound) {
            if (is_int($field->setting($bound)) || is_float($field->setting($bound))) {
                $input[$bound] = $field->setting($bound);
            }
        }

        return ['control' => 'input', 'inputProps' => $input];
    }

    protected function typeRules(UiField $field): array
    {
        $rules = [['number', (int) $field->setting('scale', 2)]];
        foreach (['min', 'max'] as $bound) {
            if (is_int($field->setting($bound)) || is_float($field->setting($bound))) {
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

    public function infers(UiColumnShape $column): int
    {
        return in_array($column->type, ['decimal', 'numeric'], true) ? 60 : 0;
    }

    public function fromColumn(UiColumnShape $column): UiField
    {
        return parent::fromColumn($column)->set('scale', $column->scale ?? 2);
    }
}
