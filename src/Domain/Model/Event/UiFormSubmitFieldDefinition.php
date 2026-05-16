<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Domain\Model\Event;

/**
 * Server-owned, signed-into-ctx definition of one field that
 * participates in a FormComponent submit.
 *
 * The compact wire shape (single-letter keys, embedded in the signed
 * `cfg.f` claim of a platform.form submit context) is kept narrow so
 * a form with many fields does not blow the signed-token size:
 *
 *   { "n": <field-name>, "i"?: <instance-id>, "r": [<rule-wire>...], "l"?: <label>, "q"?: <required> }
 *
 *   - `n` (string)    : safe field-name identifier (regex
 *                       `[A-Za-z_][A-Za-z0-9_-]*`).
 *   - `i` (string)    : OPTIONAL render-time component instance id of
 *                       the FieldComponent that renders this field.
 *                       Matches UiInstanceIdGenerator::SAFE_ID_PATTERN
 *                       (`uci_[A-Za-z0-9_-]{1,64}`). When present,
 *                       FormComponent::onSubmit projects the field's
 *                       validation result into per-field patches
 *                       targeting this instance. When absent (back-
 *                       compat for forms rendered before this slice),
 *                       only the form-level summary patches are
 *                       emitted.
 *   - `r` (list)      : normalized validation rule wire specs, ready
 *                       to feed into `UiFieldRuleParser::resolveFromWire()`.
 *                       Already vetted by the rule registry at render
 *                       time, so unknown rules / bad params fail in
 *                       the template, not at dispatch.
 *   - `l` (string)    : optional human-friendly label.
 *   - `q` (bool)      : optional required hint surfaced to the
 *                       validation context.
 *
 * The shape intentionally carries no class names, no service names,
 * no closures — every field is a safe scalar.
 */
final readonly class UiFormSubmitFieldDefinition
{
    /**
     * @param list<array<string, mixed>> $rules Normalized wire specs
     *        (each `{n, p?}`). The parser must have already vetted
     *        names + param types against the rule registry.
     * @param ?string $instanceId Optional render-time field component
     *        instance id. Must match
     *        UiInstanceIdGenerator::SAFE_ID_PATTERN.
     */
    public function __construct(
        public string  $name,
        public array   $rules,
        public ?string $label = null,
        public bool    $required = false,
        public ?string $instanceId = null,
    ) {}

    /**
     * @return array{n: string, i?: string, r: list<array<string, mixed>>, l?: string, q?: true}
     */
    public function toWireShape(): array
    {
        $out = ['n' => $this->name];
        if ($this->instanceId !== null) {
            $out['i'] = $this->instanceId;
        }
        $out['r'] = $this->rules;
        if ($this->label !== null && $this->label !== '') {
            $out['l'] = $this->label;
        }
        if ($this->required) {
            $out['q'] = true;
        }
        return $out;
    }
}
