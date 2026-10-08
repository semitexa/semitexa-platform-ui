<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Contract;

use Semitexa\PlatformUi\Domain\Model\Field\UiColumnShape;
use Semitexa\PlatformUi\Domain\Model\Field\UiField;

/**
 * One field type, five views of it: the form control, the validation rules,
 * the grid column, the filter, and how a stored column suggests it. One
 * declaration (`Field::email('contact')`) is then a form field, a column, a
 * filter and a set of rules that cannot drift apart.
 *
 * Built-ins live in Application/Service/Field/Type; a project adds its own
 * with #[AsFieldType] (a parameterless class implementing this).
 */
interface UiFieldTypeInterface
{
    /** The name `UiField::$type` refers to: 'text', 'choice', … */
    public function name(): string;

    /** One line for the catalog and for agents: what the type holds and how it is edited. */
    public function description(): string;

    /**
     * The props of the `platform.field` that edits it (control, inputProps,
     * options, rules, …). The form layer adds `value` and the instance.
     *
     * @return array<string, mixed>
     */
    public function formProps(UiField $field): array;

    /**
     * Validation rules in the UiFieldRuleParser shape — the same rules check the
     * field while it is edited (through HUG) and when the form is saved.
     *
     * @return list<string|array<int, scalar>>
     */
    public function rules(UiField $field): array;

    /**
     * The grid column (contract `ui.columns` entry): field, label, format and,
     * for a badge, variants by value.
     *
     * @return array{field: string, label: string, format: string, variants?: array<string, string>, href?: string}
     */
    public function column(UiField $field): array;

    /**
     * How the field filters a list: the operators it accepts and, for a closed
     * set, the options. Null when the type cannot be filtered.
     *
     * @return array{label: string, operators: list<string>, options?: list<array{value: string, label: string}>}|null
     */
    public function filter(UiField $field): ?array;

    /** A submitted form value as the value to store (trimmed, typed, null for empty). */
    public function cast(UiField $field, mixed $raw): mixed;

    /** How sure the type is that a stored column is one of its fields: 0 (not mine) to 100. */
    public function infers(UiColumnShape $column): int;

    /** The field this type infers from a column (only called when infers() > 0). */
    public function fromColumn(UiColumnShape $column): UiField;
}
