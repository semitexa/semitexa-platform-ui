<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Behavior;

/**
 * The declared type of a behavior option, driving client-side coercion of the
 * raw `ui-<alias>="key: val"` DSL value into a typed JS value.
 *
 * Mirrors the coercion table adapted from UIkit's prop system, but the schema is
 * authored server-side on #[AsUiBehavior] so it also feeds introspection, the
 * grammar value validator, and AI scaffolding hints.
 */
enum UiOptionType: string
{
    /** Empty attribute or "true"/"false" -> boolean. */
    case Bool = 'bool';

    /** Numeric string -> number. */
    case Number = 'number';

    /** Free string, passed through verbatim. */
    case String = 'string';

    /** One of a fixed value set (validated against UiBehaviorOption::$values). */
    case Enum = 'enum';

    /** A CSS selector string (never eval'd; resolved inside the behavior root only). */
    case Selector = 'selector';

    /** Comma-separated (paren-aware) list of strings. */
    case ListOf = 'list';
}
