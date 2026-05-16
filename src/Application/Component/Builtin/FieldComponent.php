<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin;

use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\InputPrimitive;
use Semitexa\PlatformUi\Application\Service\Validation\Rule\MinLengthRule;
use Semitexa\PlatformUi\Application\Service\Validation\Rule\RequiredRule;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldRuleParser;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldRuleRegistry;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldRuleRegistryInterface;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationContext;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidator;
use Semitexa\PlatformUi\Application\Service\Validation\UsesUiFieldRuleRegistry;
use Semitexa\PlatformUi\Attribute\ProvidesUiPart;
use Semitexa\PlatformUi\Attribute\UiOn;
use Semitexa\PlatformUi\Attribute\UiPart;
use Semitexa\PlatformUi\Attribute\UiSlot;
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
 * The HTTP dispatch endpoint (POST /__ui/dispatch) invokes it after
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
     * Plus the legacy `server-ack` setText (preserved for the dispatch
     * playground demo that opts into `showServerAckTarget: true`); the
     * frontend applier emits a `failed` lifecycle event for that one
     * when the target is absent, which is a graceful no-op.
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
        $valueAsString = is_scalar($value) ? (string) $value : '';

        // Rules come EXCLUSIVELY from the signed-ctx `cfg.r` claim —
        // the manifest builder signed them at render time, the
        // dispatcher verified the signature, and they cannot be
        // changed by the client (the payload field guard rejects
        // `rules`/`r`/`cfg`/`config` keys). If `cfg.r` is missing the
        // ctx was issued before the rules DSL existed; fall back to
        // the hardcoded demo rule so old manifests continue to work.
        $validation = $this->validateFromEvent($event, $valueAsString);

        $patches = $validation->toPatches($event->instanceId);

        // Preserve the historical server-ack patch so the existing
        // backend-dispatch demo keeps working without a parallel
        // handler. Components that don't render the server-ack target
        // see a `target_not_found` lifecycle event for this one patch
        // and the rest of the batch still applies.
        $patches[] = new UiResponsePatch(
            op: UiResponsePatch::OP_SET_TEXT,
            targetInstance: $event->instanceId,
            targetPart: null,
            targetName: 'server-ack',
            value: 'Server received: ' . $valueAsString,
        );

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
        $rawRules = $signedRules !== [] ? $signedRules : self::DEFAULT_RULES;

        $formValues = $event->formValues;
        $signedFieldName = $event->config['fn'] ?? null;
        if (is_string($signedFieldName) && $signedFieldName !== '') {
            // Signed name → trusted slot. Overwrite any client value
            // for this key with the dispatched value (which is *also*
            // client-submitted, but it's the value that triggered
            // this dispatch and is what every other consumer of the
            // event sees as the canonical "current" value).
            $formValues[$signedFieldName] = $value;
        }

        $context = new UiFieldValidationContext(
            componentName: $event->componentName,
            instanceId:    $event->instanceId,
            fieldName:     $event->partName,
            formValues:    $formValues,
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
