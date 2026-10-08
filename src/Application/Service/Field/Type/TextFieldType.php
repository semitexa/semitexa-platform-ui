<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Field\Type;

use Semitexa\PlatformUi\Domain\Model\Field\UiColumnShape;
use Semitexa\PlatformUi\Domain\Model\Field\UiField;

/** One line of text; `max` caps the length (inferred from a varchar). */
final class TextFieldType extends AbstractUiFieldType
{
    public function name(): string
    {
        return 'text';
    }

    public function description(): string
    {
        return 'One line of text. Settings: max (length).';
    }

    protected function controlProps(UiField $field): array
    {
        $input = ['type' => 'text'];
        if (is_int($field->setting('max'))) {
            $input['maxlength'] = $field->setting('max');
        }

        return ['control' => 'input', 'inputProps' => $input];
    }

    protected function typeRules(UiField $field): array
    {
        return is_int($field->setting('max')) ? [['maxLength', $field->setting('max')]] : [];
    }

    public function filter(UiField $field): ?array
    {
        return ['label' => $field->label, 'operators' => ['contains', 'eq']];
    }

    public function infers(UiColumnShape $column): int
    {
        return in_array($column->type, ['varchar', 'char', 'string'], true) ? 50 : 0;
    }

    public function fromColumn(UiColumnShape $column): UiField
    {
        $field = parent::fromColumn($column);

        return $column->length !== null ? $field->set('max', $column->length) : $field;
    }
}
