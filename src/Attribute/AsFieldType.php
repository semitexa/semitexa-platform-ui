<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Attribute;

use Attribute;
use Semitexa\Core\Attribute\Capability;

/**
 * A project field type: a parameterless class implementing
 * UiFieldTypeInterface. Its name() must not repeat a built-in or another
 * project type — boot refuses a duplicate rather than letting one shadow the
 * other.
 */
#[Capability(
    id: 'ui.field-type',
    summary: 'A field type: one declaration that is a form control, its validation rules, a grid column, a filter and an inference from a stored column.',
    useWhen: 'A value needs its own editing control or display (a rating, a colour, a money amount with a currency) everywhere it appears.',
    avoidWhen: 'A built-in type with settings or options covers it - list them with bin/semitexa platform-ui:field-types.',
    replaces: [
        'the same field described separately for the form, the grid column, the filter and the validation',
    ],
)]
#[Attribute(Attribute::TARGET_CLASS)]
final class AsFieldType
{
}
