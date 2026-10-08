<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Field\Type;

use Semitexa\PlatformUi\Domain\Model\Field\UiColumnShape;
use Semitexa\PlatformUi\Domain\Model\Field\UiField;

/** An email address: the email keyboard and autocomplete, the `email` rule. */
final class EmailFieldType extends AbstractUiFieldType
{
    public function name(): string
    {
        return 'email';
    }

    public function description(): string
    {
        return 'An email address.';
    }

    protected function controlProps(UiField $field): array
    {
        return ['control' => 'input', 'inputProps' => ['type' => 'email', 'autocomplete' => 'email']];
    }

    protected function typeRules(UiField $field): array
    {
        return ['email'];
    }

    public function filter(UiField $field): ?array
    {
        return ['label' => $field->label, 'operators' => ['contains', 'eq']];
    }

    public function cast(UiField $field, mixed $raw): mixed
    {
        $value = parent::cast($field, $raw);

        return is_string($value) ? strtolower($value) : $value;
    }

    public function infers(UiColumnShape $column): int
    {
        return in_array($column->type, ['varchar', 'char', 'string'], true) && self::nameSays($column, 'email') ? 80 : 0;
    }
}
