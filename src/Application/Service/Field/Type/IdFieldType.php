<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Field\Type;

use Semitexa\PlatformUi\Domain\Model\Field\UiColumnShape;
use Semitexa\PlatformUi\Domain\Model\Field\UiField;

/** The primary key: read-only, monospace, sortable, never on a form. */
final class IdFieldType extends AbstractUiFieldType
{
    public function name(): string
    {
        return 'id';
    }

    public function description(): string
    {
        return 'The record\'s identity: read-only, monospace, never edited.';
    }

    public function filter(UiField $field): ?array
    {
        return ['label' => $field->label, 'operators' => ['eq', 'in']];
    }

    public function column(UiField $field): array
    {
        return ['field' => $field->name, 'label' => $field->label, 'format' => 'mono'];
    }

    public function infers(UiColumnShape $column): int
    {
        return $column->isPrimaryKey ? 100 : 0;
    }

    public function fromColumn(UiColumnShape $column): UiField
    {
        return (new UiField($column->name, $this->name()))->label($column->name === 'id' ? 'ID' : UiField::humanize($column->name))->readOnly()->sortable();
    }
}
