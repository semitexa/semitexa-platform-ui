<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Twig;

use Semitexa\PlatformUi\Application\Service\Field\UiFieldTypes;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormFieldsInterface;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionRegistry;
use Semitexa\PlatformUi\Domain\Model\Field\UiField;
use Semitexa\Ssr\Application\Service\Extension\TwigExtensionRegistry;
use Semitexa\Ssr\Attribute\AsTwigExtension;

#[AsTwigExtension]
final class FieldTwigExtension
{
    public function registerFunctions(): void
    {
        /**
         * ui_form_fields(submitAction)
         *
         * The fields a form submit action declares (UiFormFieldsInterface), to
         * render with ui_field_props():
         *
         *     {% for field in ui_form_fields('contact.send') %}{{ component('platform.field', ui_field_props(field)) }}{% endfor %}
         *
         * An unknown action, or one that declares no fields, fails the render.
         */
        TwigExtensionRegistry::registerFunction(
            'ui_form_fields',
            static function (string $submitAction): array {
                $action = UiFormSubmitActionRegistry::getActive()->resolve($submitAction);
                if (!$action instanceof UiFormFieldsInterface) {
                    throw new \LogicException(sprintf('Form action "%s" does not declare its fields (%s).', $submitAction, UiFormFieldsInterface::class));
                }

                return $action->fields();
            },
        );

        /**
         * ui_field_props(field, value = null)
         *
         * The `platform.field` props for a declared field (UiField): its type's
         * control, rules and options, plus the current value —
         *
         *     {{ component('platform.field', ui_field_props(field, record[field.name] ?? null)) }}
         */
        TwigExtensionRegistry::registerFunction(
            'ui_field_props',
            static function (UiField $field, mixed $value = null): array {
                $props = UiFieldTypes::for($field)->formProps($field);
                $value ??= $field->default;
                if ($value !== null) {
                    // A moment is shown in UTC, as the form reads it back.
                    $props['value'] = $value instanceof \DateTimeInterface
                        ? \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'))->format($field->type === 'date' ? 'Y-m-d' : 'Y-m-d\TH:i')
                        : $value;
                }

                return $props;
            },
        );
    }
}
