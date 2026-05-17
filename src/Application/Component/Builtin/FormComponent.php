<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Component\Builtin;

use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\FormRootPrimitive;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionAuthorizer;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionRegistry;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitSecurityPolicy;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldRuleParser;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldRuleRegistry;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldRuleRegistryInterface;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationContext;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidator;
use Semitexa\PlatformUi\Application\Service\Validation\UiFormSubmitConfigParser;
use Semitexa\PlatformUi\Application\Service\Validation\UsesUiFieldRuleRegistry;
use Semitexa\PlatformUi\Attribute\UiOn;
use Semitexa\PlatformUi\Attribute\UiPart;
use Semitexa\PlatformUi\Attribute\UiSlot;
use Semitexa\PlatformUi\Domain\Exception\UiFieldValidationRuleException;
use Semitexa\PlatformUi\Domain\Exception\UiFormSubmitActionAuthorizationException;
use Semitexa\PlatformUi\Domain\Exception\UiFormSubmitActionException;
use Semitexa\PlatformUi\Domain\Exception\UiFormSubmitSecurityPolicyException;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionUnprocessableException;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionAuthorizationContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionResult;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitConfig;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitSecurityContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitResult;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionEvent;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionResult;
use Semitexa\PlatformUi\Domain\Model\Event\UiResponsePatch;
use Semitexa\Ssr\Attribute\AsComponent;

/**
 * Minimal composition container for grouping Platform UI fields and
 * surfacing a *client-local* aggregate of their server-validated state.
 *
 * Submit pipeline (this slice):
 *
 *   - Declares a `form` UiPart bound to FormRootPrimitive — metadata
 *     only, the template renders the actual `<form>` tag directly so
 *     the content slot, the form-status target, and the submit button
 *     all live in one natural subtree.
 *   - Declares `#[UiOn(part: 'form', event: 'submit')]` so the dispatcher
 *     can route a verified submit ctx to onSubmit().
 *   - When the template is rendered with a `fields` prop, the parsed
 *     UiFormSubmitConfig is signed into `cfg.f` of the submit ctx.
 *     Tampering breaks the HMAC; the client cannot add, remove, or
 *     retarget a field definition through the request payload (the
 *     payload field guard rejects `payload.form.rules` /
 *     `payload.form.cfg` etc.).
 *   - onSubmit() runs `UiFieldValidator` against the SIGNED rule list
 *     for each signed field, using the CLIENT-SUBMITTED
 *     `payload.form.values` as the values map. This is the
 *     authoritative final validation the cross-field input-change
 *     path explicitly defers to.
 *
 * Out of scope:
 *
 *   - No persistence. No business action. No redirect.
 *   - No per-field DOM mutation on submit (form-level summary only).
 *     Field instance ids are not currently signed in `cfg.f` because
 *     slot introspection at FormComponent render time would require
 *     a new template seam; the follow-up slice adds it cleanly.
 *   - No raw submitted values are echoed in the response debug —
 *     even non-sensitive shapes stay out of operator logs.
 *
 * Aggregation runtime (previous slice) is untouched: the form root
 * still carries `data-ui-form-aggregate="1"` and the client-local
 * aggregate updates as fields validate during input.change.
 */
#[AsComponent(
    name: 'platform.form',
    template: '@platform-ui/components/runtime/form.html.twig',
    cacheable: true,
)]
#[UiPart(
    name: 'form',
    uses: FormRootPrimitive::class,
)]
#[UiSlot(
    name: 'content',
    description: 'Caller-provided form body — typically a sequence of FieldComponents whose validation responses feed the form-level aggregate.',
)]
final class FormComponent implements UsesUiFieldRuleRegistry
{
    /**
     * Same bridge FieldComponent uses — UiInteractionDispatcher fills
     * this with the container-bound rule registry before onSubmit() runs.
     * Null falls back to the static UiFieldRuleRegistry holder, which
     * lazy-defaults to DefaultUiFieldRuleRegistry.
     */
    private ?UiFieldRuleRegistryInterface $ruleRegistry = null;

    public function withFieldRuleRegistry(UiFieldRuleRegistryInterface $registry): static
    {
        $this->ruleRegistry = $registry;
        return $this;
    }

    /**
     * Authoritative final validation for the submit demo.
     *
     * Reads the signed list of field definitions out of `cfg.f` and
     * runs every signed rule against the client-submitted
     * `payload.form.values` snapshot. Returns a form-level summary
     * — `setText` on the form-status target plus `setAttribute` for
     * `ui-state` on the form root — and a safe-to-log debug
     * projection with per-field state + message but never values.
     */
    #[UiOn(part: 'form', event: 'submit')]
    public function onSubmit(UiInteractionEvent $event): UiInteractionResult
    {
        $config = $this->resolveSignedConfig($event);
        if ($config->isEmpty()) {
            // Template rendered without a `fields` prop. Still emit
            // a friendly summary so the demo round-trips, but pin
            // the no-config case in debug so misconfigurations
            // surface during dev rather than silently passing.
            $emptyResult = UiFormSubmitResult::fromFieldResults([]);
            return UiInteractionResult::patch(
                patches: $emptyResult->toPatches($event->instanceId),
                debug: [
                    'instance' => $event->instanceId,
                    'submit'   => $emptyResult->toDebug(),
                    'reason'   => 'no_signed_fields',
                ],
            );
        }

        $registry = $this->ruleRegistry ?? UiFieldRuleRegistry::getActive();
        $parser   = new UiFieldRuleParser($registry);
        $validator = new UiFieldValidator();
        $formValues = $event->formValues;

        $perField = [];
        /** @var list<array{def: \Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitFieldDefinition, result: \Semitexa\PlatformUi\Domain\Model\Event\UiFieldValidationResult}> $fieldOutcomes */
        $fieldOutcomes = [];
        foreach ($config->fields as $def) {
            try {
                $rules = $parser->resolveFromWire($def->rules);
            } catch (UiFieldValidationRuleException $e) {
                // Bad rule in a SIGNED ctx — the parser already ran
                // these at render time, so reaching here means a
                // backwards-incompatible signer change or a tampered
                // ctx that somehow survived HMAC verification. Wrap
                // safely so the response stays opaque.
                throw new UiInteractionUnprocessableException(
                    'invalid_signed_form_field',
                    'Signed form field rule failed to resolve.',
                );
            }

            $submitted    = $formValues[$def->name] ?? null;
            $stringValue  = is_scalar($submitted) ? (string) $submitted : '';
            $fieldContext = new UiFieldValidationContext(
                componentName: $event->componentName,
                instanceId:    $def->instanceId ?? $event->instanceId,
                fieldName:     $def->name,
                label:         $def->label,
                required:      $def->required,
                formValues:    $formValues,
            );

            $result = $validator->validate(
                value:   $stringValue,
                rules:   $rules,
                context: $fieldContext,
            );

            $perField[] = [
                'name'    => $def->name,
                'state'   => $result->state,
                'message' => $result->message,
            ];
            $fieldOutcomes[] = ['def' => $def, 'result' => $result];
        }

        $summary = UiFormSubmitResult::fromFieldResults($perField);

        // Patch order: per-field patches FIRST. Form-level patches
        // come next — either the validation summary's two patches OR
        // the action result's two patches when an action ran. Action
        // extras (if any) come LAST so an action gets the final word
        // on the visible form-status / ui-state.
        $patches = [];
        $projectedInstances = [];
        foreach ($fieldOutcomes as $outcome) {
            $def    = $outcome['def'];
            $result = $outcome['result'];
            if ($def->instanceId === null) {
                continue;
            }
            foreach ($result->toPatches($def->instanceId) as $patch) {
                $patches[] = $patch;
            }
            $projectedInstances[] = $def->instanceId;
        }

        $actionResult = null;
        $signedAction = $config->actionName;
        // Carry a stable extra-debug map for the action stage so the
        // response can surface "action was wired but skipped/blocked"
        // outcomes consistently (validation_invalid / action_forbidden
        // / submit_security_failed).
        $actionDenialDebug = null;
        if ($summary->valid && $signedAction !== null) {
            // Authoritative validation passed AND the form template
            // signed an action into cfg.a — resolve, authorize, run
            // security policy, then invoke. Each gate has its own
            // typed exception; FormComponent catches authz/policy
            // failures here so they project as safe form-status
            // patches rather than as raw 4xx responses.
            try {
                $action = UiFormSubmitActionRegistry::getActive()->resolve($signedAction);
            } catch (UiFormSubmitActionException $e) {
                throw new UiInteractionUnprocessableException(
                    'unknown_signed_form_action',
                    'Signed form submit action is not registered.',
                );
            }

            $actionContext = new UiFormSubmitActionContext(
                formInstanceId: $event->instanceId,
                actionName:     $signedAction,
                dispatchId:     $event->dispatchId,
                values:         $formValues,
                fields:         $config->fields,
                submitResult:   $summary,
            );

            // Gate 1: action authorization. Runs BEFORE the security
            // policy so an unauthorized caller never reaches any
            // CSRF / session token verification — a deny-by-identity
            // failure should not depend on whether the caller managed
            // to produce a CSRF token.
            try {
                UiFormSubmitActionAuthorizer::getActive()->authorize(
                    new UiFormSubmitActionAuthorizationContext(
                        formInstanceId:       $event->instanceId,
                        actionName:           $signedAction,
                        dispatchId:           $event->dispatchId,
                        values:               $formValues,
                        fields:               $config->fields,
                        submitResult:         $summary,
                        submitActionContext:  $actionContext,
                    ),
                );
            } catch (UiFormSubmitActionAuthorizationException $e) {
                $actionDenialDebug = [
                    'name'    => $signedAction,
                    'invoked' => false,
                    'reason'  => 'action_forbidden',
                    'detail'  => $e->reasonCode,
                    'message' => $e->getMessage(),
                ];
                // $summary stays a successful-validation summary;
                // the denial message lands directly in the form-
                // status patch below via $actionDenialDebug.
            }

            // Gate 2: submit security / CSRF / session policy. Skipped
            // if the authorizer already denied — both gates together
            // form a single deny channel.
            if ($actionDenialDebug === null) {
                $securityCfg = $event->config['s'] ?? [];
                if (!is_array($securityCfg)) {
                    $securityCfg = [];
                }
                try {
                    UiFormSubmitSecurityPolicy::getActive()->verify(
                        new UiFormSubmitSecurityContext(
                            formInstanceId: $event->instanceId,
                            actionName:     $signedAction,
                            dispatchId:     $event->dispatchId,
                            fields:         $config->fields,
                            submitResult:   $summary,
                            securityConfig: $securityCfg,
                        ),
                    );
                } catch (UiFormSubmitSecurityPolicyException $e) {
                    $actionDenialDebug = [
                        'name'    => $signedAction,
                        'invoked' => false,
                        'reason'  => 'submit_security_failed',
                        'detail'  => $e->reasonCode,
                        'message' => $e->getMessage(),
                    ];
                    // $summary stays a successful-validation summary;
                // the denial message lands directly in the form-
                // status patch below via $actionDenialDebug.
                }
            }

            // Gate 3: invoke the action. Only runs if neither gate
            // above produced a denial.
            if ($actionDenialDebug === null) {
                $actionResult = $action->handle($actionContext);
            }
        }

        if ($actionResult !== null) {
            // Action ran — its message + accepted/rejected state
            // becomes the form-status text + ui-state attribute.
            $patches[] = new UiResponsePatch(
                op: UiResponsePatch::OP_SET_TEXT,
                targetInstance: $event->instanceId,
                targetPart: null,
                targetName: 'form-status',
                value: $actionResult->message,
            );
            $patches[] = new UiResponsePatch(
                op: UiResponsePatch::OP_SET_ATTRIBUTE,
                targetInstance: $event->instanceId,
                targetPart: null,
                targetName: null,
                value: $actionResult->accepted ? UiFormSubmitResult::STATE_VALID : UiFormSubmitResult::STATE_INVALID,
                attribute: 'ui-state',
            );
            foreach ($actionResult->extraPatches as $extra) {
                $patches[] = $extra;
            }
        } elseif ($actionDenialDebug !== null) {
            // Authorizer or security policy denied the action. Emit
            // the same two form-level patches as a normal action,
            // but with the denial message + ui-state="invalid". No
            // extra patches — denied actions never get to contribute.
            $patches[] = new UiResponsePatch(
                op: UiResponsePatch::OP_SET_TEXT,
                targetInstance: $event->instanceId,
                targetPart: null,
                targetName: 'form-status',
                value: $actionDenialDebug['message'],
            );
            $patches[] = new UiResponsePatch(
                op: UiResponsePatch::OP_SET_ATTRIBUTE,
                targetInstance: $event->instanceId,
                targetPart: null,
                targetName: null,
                value: UiFormSubmitResult::STATE_INVALID,
                attribute: 'ui-state',
            );
        } else {
            foreach ($summary->toPatches($event->instanceId) as $patch) {
                $patches[] = $patch;
            }
        }

        // Debug: counts + per-field state/message + form snapshot
        // *shape* (keys only). Never echo submitted values.
        $debug = [
            'instance' => $event->instanceId,
            'submit'   => $summary->toDebug(),
        ];
        if ($formValues !== []) {
            $debug['form'] = [
                'snapshotFields' => array_keys($formValues),
                'snapshotSize'   => count($formValues),
            ];
        }
        if ($projectedInstances !== []) {
            $debug['submit']['projectedFieldInstances'] = $projectedInstances;
        }
        if ($actionResult !== null) {
            $debug['action'] = $actionResult->toDebug() + ['name' => $signedAction];
        } elseif ($actionDenialDebug !== null) {
            // Authorizer or security policy blocked the action.
            // Surface the typed reason + detail token without
            // including raw values or class names.
            $debug['action'] = $actionDenialDebug;
        } elseif ($signedAction !== null) {
            // Signed action exists but validation failed → action
            // intentionally skipped. Surface that fact in debug so
            // operators can correlate "action wired but never ran"
            // without echoing values.
            $debug['action'] = [
                'name'    => $signedAction,
                'invoked' => false,
                'reason'  => 'validation_invalid',
            ];
        }

        return UiInteractionResult::patch(patches: $patches, debug: $debug);
    }

    private function resolveSignedConfig(UiInteractionEvent $event): UiFormSubmitConfig
    {
        $wire = $event->config['f'] ?? null;
        $actionName = $this->extractSignedActionName($event);

        if ($wire === null) {
            return new UiFormSubmitConfig([], $actionName);
        }
        try {
            $config = (new UiFormSubmitConfigParser($this->parser($event)))
                ->parseSignedWire($wire);
        } catch (UiFieldValidationRuleException $e) {
            throw new UiInteractionUnprocessableException(
                'invalid_signed_form_config',
                'Signed form submit configuration could not be read.',
            );
        }
        // Re-wrap so the action name lands on the returned config —
        // the parser returns a fields-only UiFormSubmitConfig and we
        // do not want to widen its signature for action data.
        return new UiFormSubmitConfig($config->fields, $actionName);
    }

    /**
     * Pull the signed action name out of `cfg.a`. The claim survived
     * HMAC verification so it is server-trusted; we still defensively
     * re-check its shape so a future signer change that lands a wrong
     * type at this key cannot reach the registry.
     */
    private function extractSignedActionName(UiInteractionEvent $event): ?string
    {
        $signed = $event->config['a'] ?? null;
        if ($signed === null) {
            return null;
        }
        if (!is_string($signed) || $signed === '') {
            throw new UiInteractionUnprocessableException(
                'invalid_signed_form_action',
                'Signed form submit action name has the wrong shape.',
            );
        }
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_.-]{0,127}\z/', $signed) !== 1) {
            throw new UiInteractionUnprocessableException(
                'invalid_signed_form_action',
                'Signed form submit action name has the wrong shape.',
            );
        }
        return $signed;
    }

    private function parser(UiInteractionEvent $event): UiFieldRuleParser
    {
        $registry = $this->ruleRegistry ?? UiFieldRuleRegistry::getActive();
        return new UiFieldRuleParser($registry);
    }
}
