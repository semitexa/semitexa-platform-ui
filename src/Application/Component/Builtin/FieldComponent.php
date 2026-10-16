<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin;

use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValue;

use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\InputPrimitive;
use Semitexa\PlatformUi\Application\Service\Validation\Rule\MinLengthRule;
use Semitexa\PlatformUi\Application\Service\Validation\Rule\RequiredRule;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldRuleParser;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldRuleRegistry;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldRuleRegistryInterface;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationContext;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidator;
use Semitexa\PlatformUi\Application\Service\Validation\UsesUiFieldRuleRegistry;
use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Attribute\ProvidesUiPart;
use Semitexa\PlatformUi\Attribute\UiOn;
use Semitexa\PlatformUi\Attribute\UiPart;
use Semitexa\PlatformUi\Attribute\UiSlot;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;
use Semitexa\PlatformUi\Domain\Exception\UiFieldValidationRuleException;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionUnprocessableException;
use Semitexa\PlatformUi\Domain\Model\Event\UiFieldValidationResult;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionEvent;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionResult;
use Semitexa\PlatformUi\Domain\Model\Event\UiResponsePatch;
use Semitexa\Ssr\Attribute\AsComponent;

/**
 * The smallest useful composed Platform UI component.
 *
 * Composition shape:
 *   - input part   → InputPrimitive (rendered through the primitive() helper)
 *   - label        → plain text from the `label` prop (no primitive yet)
 *   - help / error → plain text from `help` / `error` props (no primitive yet)
 *   - prefix slot  → caller-provided content rendered before the input
 *   - suffix slot  → caller-provided content rendered after the input
 *
 * Identity is `platform.field`; the template wires layout (label, slot
 * placement, help/error markup) and delegates input prop construction to
 * the inputPart() provider through #[ProvidesUiPart]. The input's `value`
 * prop is owned by the bind step of UiPartPropResolver via
 * #[UiPart(bind: 'value')] — the provider only handles structural props.
 * No event hooks, no live validation in this slice.
 *
 * Accepted caller props:
 *   label, name, id, type, value, placeholder, size, required, disabled,
 *   state, help, error, inputProps.
 *
 * `value` flows through #[UiPart(bind: 'value')]. `inputProps` is the
 * explicit override map merged onto the resolved input primitive props
 * after defaults → provider → bind — caller overrides always win.
 *
 * Event metadata: the `onInputChanged` method is the declared handler for
 * the `input.change` event (inherits the bound `value` path as `updates`).
 * HUG (POST /__semitexa_hug) invokes it after
 * SignedContext verification and UiOn resolution. The handler is
 * intentionally ack-only — it does NOT persist, validate, or patch DOM.
 */
#[AsComponent(
    name: 'platform.field',
    template: '@platform-ui/components/runtime/field.html.twig',
    cacheable: true,
)]
#[UiPart(
    name: 'input',
    uses: InputPrimitive::class,
    defaults: ['type' => 'text'],
    bind: 'value',
)]
#[UiSlot(name: 'prefix', description: 'Optional content rendered immediately before the input — typically an icon or short label.')]
#[UiSlot(name: 'suffix', description: 'Optional content rendered immediately after the input — typically a small button or hint.')]
#[AsUiContract(
    summary: 'A labelled form control — text, textarea, select, choices, switch or file — with help, error and server-checked rules.',
    props: [
        new UiProp('label', nullable: true, description: 'Visible label; a legend for radio/checkbox groups.'),
        new UiProp('name', nullable: true, description: 'Form key; also keys the field in the form snapshot.'),
        new UiProp('id', nullable: true, description: 'Defaults to name.'),
        new UiProp('control', default: 'input', values: ['input', 'textarea', 'select', 'checkbox', 'switch', 'checkboxes', 'radio', 'segmented', 'file']),
        new UiProp('type', default: 'text', values: ['text', 'email', 'password', 'search', 'url', 'tel', 'number', 'date', 'datetime-local', 'time', 'month', 'week', 'color', 'range', 'hidden'], description: 'Input type when control is input.'),
        new UiProp('value', UiPropType::Scalar, nullable: true, description: 'Current value; true or "1" checks a checkbox or switch.'),
        new UiProp('values', UiPropType::Array, nullable: true, items: new UiProp('value', UiPropType::Scalar), description: 'Selected values of a multiple select or checkbox group.'),
        new UiProp('placeholder', nullable: true),
        new UiProp('size', default: 'md', values: ['sm', 'md', 'lg']),
        new UiProp('state', nullable: true, values: ['default', 'invalid']),
        new UiProp('required', UiPropType::Boolean, default: false),
        new UiProp('disabled', UiPropType::Boolean, default: false),
        new UiProp('help', nullable: true, description: 'Muted help text, linked by aria-describedby.'),
        new UiProp('error', nullable: true, description: 'Error text; sets the invalid state and aria-invalid.'),
        new UiProp('options', UiPropType::Array, default: [], items: new UiProp('option', UiPropType::Object), description: 'Choices as {value, label} for select, checkboxes, radio and segmented.'),
        new UiProp('multiple', UiPropType::Boolean, default: false, description: 'A multiple select.'),
        new UiProp('checkboxLabel', nullable: true, description: 'Text beside a checkbox or switch when label is the heading.'),
        new UiProp('rows', UiPropType::Integer, default: 6, description: 'Textarea height.'),
        new UiProp('accept', UiPropType::Array, nullable: true, items: new UiProp('mime'), description: 'File control: accepted MIME patterns (default image/*).'),
        new UiProp('maxBytes', UiPropType::Integer, nullable: true, description: 'File control: size limit in bytes (default 1 MiB).'),
        new UiProp('shrinkImages', UiPropType::Boolean, default: true, description: 'File control: shrink a photo in the browser before upload (2400 px, JPEG, EXIF dropped); GIF, SVG, transparent images and fields that do not take JPEG are sent as chosen.'),
        new UiProp('rules', UiPropType::Array, default: [], description: 'Validation rules, signed into the page: "required", ["minLength", 5], …'),
        new UiProp('inputProps', UiPropType::Object, default: [], description: 'Overrides merged onto the input primitive last.'),
        new UiProp('showValidationTarget', UiPropType::Boolean, default: false, description: 'Render the live validation message the server patches.'),
        new UiProp('showServerAckTarget', UiPropType::Boolean, default: false, description: 'Render the server echo of each change (demo).'),
        new UiProp('instanceId', nullable: true, description: 'A stable instance id; generated when omitted.'),
    ],
    examples: [
        new UiExample('default', 'Email', ['label' => 'Email', 'name' => 'email', 'type' => 'email', 'placeholder' => 'you@example.com', 'help' => 'We never share it.']),
        new UiExample('required', 'Required, checked live', ['label' => 'Display name', 'name' => 'display_name', 'required' => true, 'rules' => ['required', ['minLength', 3]], 'showValidationTarget' => true]),
        new UiExample('error', 'With error', ['label' => 'Email', 'name' => 'email_bad', 'type' => 'email', 'value' => 'jane@', 'error' => 'Enter a complete email address.']),
        new UiExample('disabled', 'Disabled', ['label' => 'Workspace', 'name' => 'workspace', 'value' => 'Acme', 'disabled' => true, 'help' => 'Only an owner can rename it.']),
        new UiExample('select', 'Select', ['label' => 'Plan', 'name' => 'plan', 'control' => 'select', 'value' => 'team', 'options' => [['value' => 'solo', 'label' => 'Solo'], ['value' => 'team', 'label' => 'Team'], ['value' => 'enterprise', 'label' => 'Enterprise']]]),
        new UiExample('radio', 'Radio group', ['label' => 'Billing', 'name' => 'billing', 'control' => 'radio', 'value' => 'monthly', 'options' => [['value' => 'monthly', 'label' => 'Monthly'], ['value' => 'yearly', 'label' => 'Yearly']]]),
        new UiExample('switch', 'Switch', ['label' => 'Notifications', 'name' => 'notify', 'control' => 'switch', 'checkboxLabel' => 'Email me about replies', 'value' => '1']),
        new UiExample('textarea', 'Textarea', ['label' => 'Message', 'name' => 'message', 'control' => 'textarea', 'rows' => 4, 'placeholder' => 'Write a few lines…']),
    ],
    previewSafe: true,
    // A Workbench preview, not yet a block an AI-composed screen may use.
    agent: false,
)]
final class FieldComponent implements UsesUiFieldRuleRegistry
{
    /**
     * Active validation rule registry, supplied by
     * UiInteractionDispatcher's UsesUiFieldRuleRegistry bridge before
     * onInputChanged() runs. Null means "fall back to the
     * UiFieldRuleRegistry static holder", which itself defaults to a
     * fresh DefaultUiFieldRuleRegistry — keeping standalone unit
     * tests that construct FieldComponent directly (no dispatcher,
     * no container) producing the same built-ins-only behaviour as
     * production with the default registry.
     */
    private ?UiFieldRuleRegistryInterface $ruleRegistry = null;

    public function withFieldRuleRegistry(UiFieldRuleRegistryInterface $registry): static
    {
        // Mutate in place rather than cloning: the dispatcher
        // already creates a fresh component instance per dispatch
        // (newInstance() with no shared state) so there's nothing to
        // protect against accidental reuse. Cloning would require
        // every concrete component to opt into __clone behaviour.
        $this->ruleRegistry = $registry;
        return $this;
    }

    /**
     * Build the structural prop map for the `input` part.
     *
     * Pure projection of caller component props onto primitive props:
     *   - id falls back to name when omitted;
     *   - error implies state='invalid' + aria-invalid + describedby('-error');
     *   - help (without error) wires aria-describedby('-help').
     *
     * The input's `value` prop is intentionally NOT emitted here — it's
     * resolved through #[UiPart(bind: 'value')] in the bind step of the
     * resolver, after this provider runs. Callers can still override it
     * via `inputProps.value`, which runs last.
     *
     * This method MUST NOT do IO.
     *
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    #[ProvidesUiPart(part: 'input')]
    public function inputPart(array $props): array
    {
        $id = self::scalarOrNull($props['id'] ?? null) ?? self::scalarOrNull($props['name'] ?? null);
        $hasError = self::isPresent($props['error'] ?? null);
        $hasHelp = self::isPresent($props['help'] ?? null);
        $hasValidationTarget = (bool) ($props['showValidationTarget'] ?? false);

        // aria-describedby may need to point at TWO ids: the existing
        // help/error span AND the validation-message span (when the
        // caller opts into showValidationTarget). The HTML spec accepts
        // a space-separated list, so we concatenate.
        $describedByIds = [];
        if ($id !== null) {
            if ($hasError) {
                $describedByIds[] = $id . '-error';
            } elseif ($hasHelp) {
                $describedByIds[] = $id . '-help';
            }
            if ($hasValidationTarget) {
                $describedByIds[] = $id . '-validation';
            }
        }
        $describedBy = $describedByIds === [] ? null : implode(' ', $describedByIds);

        return [
            'name' => self::scalarOrNull($props['name'] ?? null),
            'id' => $id,
            'type' => self::scalarOrNull($props['type'] ?? null) ?? 'text',
            'placeholder' => self::scalarOrNull($props['placeholder'] ?? null),
            'size' => self::scalarOrNull($props['size'] ?? null),
            'state' => $hasError ? 'invalid' : self::scalarOrNull($props['state'] ?? null),
            'required' => (bool) ($props['required'] ?? false),
            'disabled' => (bool) ($props['disabled'] ?? false),
            'aria_invalid' => $hasError ? true : null,
            'aria_describedby' => $describedBy,
        ];
    }

    /**
     * Constants kept for backward compatibility with the previous
     * hardcoded-rule slice. Tests that pinned these strings continue
     * to pass because the rule-name → message mapping in RequiredRule
     * / MinLengthRule emits the same diagnostics for the same inputs.
     */
    public const VALIDATION_MIN_LENGTH = 3;
    public const VALIDATION_MESSAGE_REQUIRED = RequiredRule::MESSAGE;
    public const VALIDATION_MESSAGE_TOO_SHORT = 'Please enter at least 3 characters.';
    public const VALIDATION_MESSAGE_OK = 'Looks good.';

    /**
     * Defaults applied when no `rules` prop is provided. Preserves the
     * previous slice's behavior so existing tests / playground demos
     * continue to render with the same UX out-of-the-box.
     *
     * Apps override by setting `rules: ['required', ['minLength', 5], …]`
     * on the component prop list.
     */
    public const DEFAULT_RULES = [
        ['required'],
        ['minLength', self::VALIDATION_MIN_LENGTH],
    ];

    /**
     * Declared handler for the input.change event.
     *
     * Invoked by UiInteractionDispatcher after SignedContext verification
     * and UiOn resolution. Runs a small demo validation on the captured
     * value and returns the existing `UiResponsePatch` shape:
     *
     *   - setAttribute on the input part: aria-invalid (true / removed)
     *   - setAttribute on the input part: ui-state (valid / invalid)
     *   - setText on the validation-message name target with the
     *     diagnostic.
     *
     * Plus the legacy `server-ack` setText, only for a field rendered with
     * `showServerAckTarget: true` (signed into the ctx as `cfg.ack`).
     *
     * The validation rules are intentionally minimal — empty / <3 chars
     * are invalid, everything else is valid. This is a DEMO of the
     * patch shape, not a real form engine. No persistence, no rule
     * DSL, no state store.
     */
    #[UiOn(part: 'input', event: 'change')]
    public function onInputChanged(UiInteractionEvent $event): UiInteractionResult
    {
        $value = $event->value();
        $valueAsString = UiFieldValue::asString($value);

        // Rules come EXCLUSIVELY from the signed-ctx `cfg.r` claim —
        // the manifest builder signed them at render time, the
        // dispatcher verified the signature, and they cannot be
        // changed by the client (the payload field guard rejects
        // `rules`/`r`/`cfg`/`config` keys). If `cfg.r` is missing the
        // ctx was issued before the rules DSL existed; fall back to
        // the hardcoded demo rule so old manifests continue to work.
        $validation = $this->validateFromEvent($event, $valueAsString);

        $patches = $validation->toPatches($event->instanceId);

        // The historical server-ack echo, only for a field rendered with
        // `showServerAckTarget` (signed as cfg.ack): everywhere else it was a
        // `target_not_found` failure that also put the typed value on the wire.
        if (($event->config['ack'] ?? false) === true) {
            $patches[] = new UiResponsePatch(
                op: UiResponsePatch::OP_SET_TEXT,
                targetInstance: $event->instanceId,
                targetPart: null,
                targetName: 'server-ack',
                value: 'Server received: ' . $valueAsString,
            );
        }

        // Surface only the SHAPE of the form snapshot in debug — never
        // the values. Sibling values may be sensitive (codes, tokens);
        // operators correlating logs need the *fact* that a snapshot
        // arrived, plus the list of keys, but nothing more.
        $debug = [
            'value' => $value,
            'instance' => $event->instanceId,
            'validation' => [
                'state' => $validation->state,
                'message' => $validation->message,
            ],
        ];
        if ($event->formValues !== []) {
            $debug['form'] = [
                'snapshotFields' => array_keys($event->formValues),
                'snapshotSize'   => count($event->formValues),
            ];
        }

        return UiInteractionResult::patch(patches: $patches, debug: $debug);
    }

    /**
     * Default validation entry point. Runs the documented DEFAULT_RULES
     * — kept for backward compat with the previous slice's tests.
     * Real callers should pass an explicit rule list through the
     * component's `rules` prop; the signed manifest carries that list
     * into the dispatch handler.
     *
     * Pure: no IO, no globals, no $event mutation. Test-friendly.
     */
    public function validate(string $value): UiFieldValidationResult
    {
        return $this->runValidation($value, self::DEFAULT_RULES, $this->demoContext());
    }

    /**
     * Hot path: take rule specs out of the signed ctx and run them.
     * Falls back to DEFAULT_RULES when the manifest predates the cfg
     * claim or carries no `r` list. Wraps parser/resolver failures as
     * a typed unprocessable response so misconfigured rules surface
     * safely without leaking class FQCNs.
     *
     * Cross-field rules (e.g. sameAsField) read sibling values out of
     * `UiFieldValidationContext::formValues`. The dispatcher already
     * sanitised the client-submitted snapshot through
     * UiFormPayloadSnapshot; the handler additionally self-merges the
     * current field's `$value` under the signed field name (`cfg.fn`)
     * so a self-referencing rule, or a frontend that forgot to
     * include the current field in its snapshot, still sees the
     * current value as authoritative. Self-merge always wins over
     * what the client sent for this same key — clients cannot
     * smuggle a different "current" value through the snapshot.
     */
    private function validateFromEvent(UiInteractionEvent $event, string $value): UiFieldValidationResult
    {
        $signedRules = $event->rules();
        $signedFieldName = $event->config['fn'] ?? null;
        if ($signedRules === [] && is_string($signedFieldName) && $signedFieldName !== '') {
            // A named field rendered without rules declared none: nothing to
            // check (a switch is not "at least 3 characters"). The demo rule
            // below is only for contexts that carry neither.
            return UiFieldValidationResult::valid();
        }
        $rawRules = $signedRules !== [] ? $signedRules : self::DEFAULT_RULES;

        $formValues = $event->formValues;
        if (is_string($signedFieldName) && $signedFieldName !== '') {
            // Signed name → trusted slot. Overwrite any client value
            // for this key with the dispatched value (which is *also*
            // client-submitted, but it's the value that triggered
            // this dispatch and is what every other consumer of the
            // event sees as the canonical "current" value).
            $formValues[$signedFieldName] = $value;
        }

        $submitted = $event->value();
        $context = new UiFieldValidationContext(
            componentName: $event->componentName,
            instanceId:    $event->instanceId,
            fieldName:     $event->partName,
            formValues:    $formValues,
            submittedList: is_array($submitted) ? array_values(array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $submitted)) : null,
        );

        try {
            return $this->runValidation($value, $rawRules, $context);
        } catch (UiFieldValidationRuleException $e) {
            throw new UiInteractionUnprocessableException(
                'invalid_validation_rule',
                'Validation rule spec is malformed.',
            );
        }
    }

    /**
     * @param array<int, mixed> $rawRules
     */
    private function runValidation(
        string $value,
        array $rawRules,
        UiFieldValidationContext $context,
    ): UiFieldValidationResult {
        // Prefer the registry the dispatcher provided through the
        // UsesUiFieldRuleRegistry bridge. Fall back to the static
        // UiFieldRuleRegistry holder (production: container-bound
        // winner set by BootPlatformUiRegistryListener; tests:
        // whatever UiFieldRuleRegistry::setActive() was called with;
        // otherwise: lazy-default DefaultUiFieldRuleRegistry).
        $registry = $this->ruleRegistry ?? UiFieldRuleRegistry::getActive();
        $parser = new UiFieldRuleParser($registry);

        // The signed ctx already carries the rules in wire shape
        // ({n, p}). The default-rules path passes the DSL shape
        // (['required'] / ['minLength', 3]). Try the wire shape first;
        // fall back to the DSL parser when the input doesn't match.
        $rules = $this->isWireShape($rawRules)
            ? $parser->resolveFromWire($rawRules)
            : $parser->resolveAll($parser->parseAll($rawRules));

        return (new UiFieldValidator())->validate(
            value:   $value,
            rules:   $rules,
            context: $context,
        );
    }

    /**
     * @param array<int, mixed> $rawRules
     */
    private function isWireShape(array $rawRules): bool
    {
        if ($rawRules === []) {
            return false;
        }
        $first = $rawRules[array_key_first($rawRules)] ?? null;
        return is_array($first) && isset($first['n']) && is_string($first['n']);
    }

    private function demoContext(): UiFieldValidationContext
    {
        return new UiFieldValidationContext(
            componentName: 'platform.field',
            instanceId:    'uci_demo',
            fieldName:     'input',
        );
    }

    private static function scalarOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_scalar($value)) {
            $str = (string) $value;
            return $str === '' ? null : $str;
        }
        return null;
    }

    private static function isPresent(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_string($value)) {
            return $value !== '';
        }
        return (bool) $value;
    }
}
