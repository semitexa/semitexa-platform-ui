<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Field\Type;

use Semitexa\PlatformUi\Domain\Model\Field\UiColumnShape;
use Semitexa\PlatformUi\Domain\Model\Field\UiField;

/** Several lines of plain text; shown on forms and the detail view, not as a grid column by default. */
final class TextareaFieldType extends AbstractUiFieldType
{
    public function name(): string
    {
        return 'textarea';
    }

    public function description(): string
    {
        return 'Several lines of text. Settings: rows, max.';
    }

    protected function controlProps(UiField $field): array
    {
        return ['control' => 'textarea', 'rows' => (int) $field->setting('rows', 6)];
    }

    protected function typeRules(UiField $field): array
    {
        return is_int($field->setting('max')) ? [['maxLength', $field->setting('max')]] : [];
    }

    public function filter(UiField $field): ?array
    {
        return null;
    }

    public function infers(UiColumnShape $column): int
    {
        return in_array($column->type, ['text', 'mediumtext', 'longtext'], true) ? 60 : 0;
    }

    public function fromColumn(UiColumnShape $column): UiField
    {
        return parent::fromColumn($column)->hideOnList();
    }
}
