<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Field\Type;

use Semitexa\PlatformUi\Domain\Model\Field\UiColumnShape;
use Semitexa\PlatformUi\Domain\Model\Field\UiField;

/** A URL-safe identifier ("release-notes"); `from` names the field it is suggested from. */
final class SlugFieldType extends AbstractUiFieldType
{
    public function name(): string
    {
        return 'slug';
    }

    public function description(): string
    {
        return 'A URL-safe identifier: lowercase words joined by hyphens. Settings: from (the field it is derived from), max.';
    }

    protected function controlProps(UiField $field): array
    {
        $input = ['type' => 'text', 'autocomplete' => 'off'];
        if (is_int($field->setting('max'))) {
            $input['maxlength'] = $field->setting('max');
        }

        return ['control' => 'input', 'inputProps' => $input];
    }

    protected function typeRules(UiField $field): array
    {
        $rules = ['slug'];
        if (is_int($field->setting('max'))) {
            $rules[] = ['maxLength', $field->setting('max')];
        }

        return $rules;
    }

    public function column(UiField $field): array
    {
        return ['field' => $field->name, 'label' => $field->label, 'format' => 'mono'];
    }

    public function cast(UiField $field, mixed $raw): mixed
    {
        $value = parent::cast($field, $raw);

        return is_string($value) ? strtolower($value) : $value;
    }

    public function infers(UiColumnShape $column): int
    {
        return in_array($column->type, ['varchar', 'char', 'string'], true) && self::nameSays($column, 'slug') ? 80 : 0;
    }

    public function fromColumn(UiColumnShape $column): UiField
    {
        $field = parent::fromColumn($column);

        return $column->length !== null ? $field->set('max', $column->length) : $field;
    }
}
