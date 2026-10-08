<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Field\Type;

use Semitexa\PlatformUi\Domain\Model\Field\UiColumnShape;
use Semitexa\PlatformUi\Domain\Model\Field\UiField;

/** Structured data; read-only (a JSON textarea is not an editor anyone should be handed). */
final class JsonFieldType extends AbstractUiFieldType
{
    public function name(): string
    {
        return 'json';
    }

    public function description(): string
    {
        return 'Structured data, shown read-only as formatted JSON.';
    }

    public function column(UiField $field): array
    {
        return ['field' => $field->name, 'label' => $field->label, 'format' => 'mono'];
    }

    public function filter(UiField $field): ?array
    {
        return null;
    }

    public function infers(UiColumnShape $column): int
    {
        return $column->type === 'json' ? 60 : 0;
    }

    public function fromColumn(UiColumnShape $column): UiField
    {
        return (new UiField($column->name, $this->name()))->readOnly()->hideOnList();
    }
}
