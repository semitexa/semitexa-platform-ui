<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Submit;

use Semitexa\PlatformUi\Domain\Model\Field\UiField;

/**
 * A form submit action that declares its form's fields. The template renders
 * them (`ui_form_fields('contact.send')`) and the action casts the submitted
 * values with the same list (UiFieldSet::cast), so the control, its rules and
 * the stored value come from one declaration.
 */
interface UiFormFieldsInterface
{
    /** @return list<UiField> */
    public function fields(): array;
}
