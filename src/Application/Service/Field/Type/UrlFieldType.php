<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Field\Type;

use Semitexa\PlatformUi\Domain\Model\Field\UiColumnShape;
use Semitexa\PlatformUi\Domain\Model\Field\UiField;

/** An absolute http(s) address; the column is a link to it. */
final class UrlFieldType extends AbstractUiFieldType
{
    public function name(): string
    {
        return 'url';
    }

    public function description(): string
    {
        return 'An absolute http(s) web address, shown as a link.';
    }

    protected function controlProps(UiField $field): array
    {
        return ['control' => 'input', 'inputProps' => ['type' => 'url', 'autocomplete' => 'url']];
    }

    protected function typeRules(UiField $field): array
    {
        return ['url'];
    }

    public function column(UiField $field): array
    {
        return ['field' => $field->name, 'label' => $field->label, 'format' => 'url'];
    }

    public function filter(UiField $field): ?array
    {
        return ['label' => $field->label, 'operators' => ['contains']];
    }

    public function infers(UiColumnShape $column): int
    {
        return in_array($column->type, ['varchar', 'char', 'string', 'text'], true) && self::nameSays($column, 'url', 'website', 'homepage', 'link') ? 70 : 0;
    }
}
