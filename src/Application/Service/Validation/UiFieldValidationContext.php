<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Validation;

/**
 * Read-only context handed to every UiFieldValidationRuleInterface
 * implementation. Carries the signed-ctx identity (component name,
 * instance id, field name, label) plus a few diagnostic-friendly
 * fields — but NOT the request object, NOT services, NOT signed
 * claims verbatim.
 *
 * Cross-field rules (this slice) additionally read sibling field
 * values through {@see self::formValue()}. The map is populated by
 * the dispatcher from the *sanitised* `payload.form.values` snapshot
 * — client-submitted, identifier-keyed, scalar-or-null only. Rules
 * MUST treat sibling values as untrusted UX-feedback input, not as
 * authoritative state; the final-submit pipeline (future slice)
 * will revalidate everything server-side.
 *
 * Rules should be pure: input value + context → optional failure
 * result. They MUST NOT make HTTP calls, MUST NOT touch the database,
 * MUST NOT cache state.
 */
final readonly class UiFieldValidationContext
{
    /**
     * @param array<string, scalar|null> $formValues Sibling field values,
     *        sanitised: keys match the safe identifier shape
     *        `[A-Za-z_][A-Za-z0-9_-]*`, values are scalar or null.
     *        Never contains rule specs, signed claims, or routing
     *        identity.
     */
    public function __construct(
        public string  $componentName,
        public string  $instanceId,
        public string  $fieldName,
        public ?string $label = null,
        public bool    $required = false,
        public array   $formValues = [],
    ) {}

    /**
     * Read a sibling field value out of the sanitised form snapshot.
     * Returns null when the field is absent. Callers should treat null
     * as "missing from snapshot" rather than "empty string" — empty
     * strings are still legal scalar values and pass through unchanged.
     */
    public function formValue(string $fieldName): mixed
    {
        return $this->formValues[$fieldName] ?? null;
    }
}
