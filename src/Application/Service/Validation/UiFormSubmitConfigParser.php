<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Validation;

use Semitexa\PlatformUi\Application\Service\Event\UiInstanceIdGenerator;
use Semitexa\PlatformUi\Domain\Exception\UiFieldValidationRuleException;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitConfig;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitFieldDefinition;

/**
 * Translates the developer-facing `fields:` prop on FormComponent
 * into the signed-wire-ready UiFormSubmitConfig.
 *
 * Developer-facing DSL (caller side):
 *
 *   fields: [
 *     {
 *       name: 'access_code',
 *       label: 'Access code',                                 // optional
 *       required: true,                                       // optional
 *       rules: ['required', ['minLength', 4]],                // same DSL as FieldComponent
 *     },
 *     ...
 *   ]
 *
 * Output: a UiFormSubmitConfig whose toWireShape() lands in `cfg.f`
 * of the signed submit ctx.
 *
 * Security perimeter (this class):
 *
 *   - `name` must be a safe identifier `[A-Za-z_][A-Za-z0-9_-]*`.
 *     Rejecting hostile names here keeps the signed cfg uniform with
 *     the patch validator and the FieldComponent template's own
 *     `data-ui-field-name` filter.
 *   - `rules` go through UiFieldRuleParser → registry. Unknown rule
 *     names, malformed params, or callable smuggling fail at render
 *     time as `UiFieldValidationRuleException` (which the Twig layer
 *     surfaces as a clear template error).
 *   - `label` is coerced to a string and capped at a reasonable
 *     ceiling — labels live in the signed ctx so an unbounded label
 *     would inflate every request.
 *   - duplicate field names are rejected — two fields sharing a
 *     name would silently overwrite each other in the submitted
 *     snapshot.
 */
final class UiFormSubmitConfigParser
{
    public const MAX_FIELDS       = 50;
    public const MAX_LABEL_LENGTH = 200;

    private const SAFE_IDENTIFIER = '/\A[A-Za-z_][A-Za-z0-9_-]*\z/';

    private readonly UiFieldRuleParser $ruleParser;

    public function __construct(?UiFieldRuleParser $ruleParser = null)
    {
        $this->ruleParser = $ruleParser ?? new UiFieldRuleParser(UiFieldRuleRegistry::getActive());
    }

    /**
     * @param array<int, mixed> $rawFields
     *
     * @throws UiFieldValidationRuleException
     */
    public function parse(array $rawFields): UiFormSubmitConfig
    {
        if (!array_is_list($rawFields)) {
            throw new UiFieldValidationRuleException(
                'Form submit `fields` must be a plain list of field definitions.',
            );
        }
        if (count($rawFields) > self::MAX_FIELDS) {
            throw new UiFieldValidationRuleException(sprintf(
                'Form submit `fields` may not exceed %d entries.',
                self::MAX_FIELDS,
            ));
        }

        $seen = [];
        $seenInstanceIds = [];
        $defs = [];
        foreach ($rawFields as $index => $rawField) {
            $def = $this->parseOne($rawField, $index);
            if (isset($seen[$def->name])) {
                throw new UiFieldValidationRuleException(sprintf(
                    'Form submit `fields[%d]` declares duplicate field name "%s".',
                    $index,
                    $def->name,
                ));
            }
            $seen[$def->name] = true;
            if ($def->instanceId !== null) {
                if (isset($seenInstanceIds[$def->instanceId])) {
                    throw new UiFieldValidationRuleException(sprintf(
                        'Form submit `fields[%d]` declares duplicate instanceId "%s".',
                        $index,
                        $def->instanceId,
                    ));
                }
                $seenInstanceIds[$def->instanceId] = true;
            }
            $defs[] = $def;
        }
        return new UiFormSubmitConfig($defs);
    }

    /**
     * @param mixed $rawField
     */
    private function parseOne($rawField, int $index): UiFormSubmitFieldDefinition
    {
        if (!is_array($rawField) || array_is_list($rawField)) {
            throw new UiFieldValidationRuleException(sprintf(
                'Form submit `fields[%d]` must be an object with `name` and `rules`.',
                $index,
            ));
        }

        $name = $rawField['name'] ?? null;
        if (!is_string($name) || preg_match(self::SAFE_IDENTIFIER, $name) !== 1) {
            throw new UiFieldValidationRuleException(sprintf(
                'Form submit `fields[%d].name` must match [A-Za-z_][A-Za-z0-9_-]*.',
                $index,
            ));
        }

        $rawRules = $rawField['rules'] ?? [];
        if (!is_array($rawRules)) {
            throw new UiFieldValidationRuleException(sprintf(
                'Form submit `fields[%d].rules` must be a list.',
                $index,
            ));
        }
        // parseAllToWire = registry-validate names + params, emit wire shape.
        // We capture the wire output verbatim — the same shape the
        // FieldComponent template signs into cfg.r.
        $ruleWire = $this->ruleParser->parseAllToWire($rawRules);

        $label = $rawField['label'] ?? null;
        if ($label !== null) {
            if (!is_string($label)) {
                throw new UiFieldValidationRuleException(sprintf(
                    'Form submit `fields[%d].label` must be a string when provided.',
                    $index,
                ));
            }
            if (mb_strlen($label) > self::MAX_LABEL_LENGTH) {
                throw new UiFieldValidationRuleException(sprintf(
                    'Form submit `fields[%d].label` exceeds %d characters.',
                    $index,
                    self::MAX_LABEL_LENGTH,
                ));
            }
            if ($label === '') {
                $label = null;
            }
        }

        $required = (bool) ($rawField['required'] ?? false);

        $instanceId = $rawField['instanceId'] ?? null;
        if ($instanceId !== null) {
            if (!UiInstanceIdGenerator::isSafe($instanceId)) {
                throw new UiFieldValidationRuleException(sprintf(
                    'Form submit `fields[%d].instanceId` must match the safe instance-id shape (%s).',
                    $index,
                    UiInstanceIdGenerator::SAFE_ID_PATTERN,
                ));
            }
            /** @var string $instanceId */
        }

        return new UiFormSubmitFieldDefinition(
            name: $name,
            rules: $ruleWire,
            label: $label,
            required: $required,
            instanceId: $instanceId,
        );
    }

    /**
     * Round-trip parser for the *signed* form-submit config sitting at
     * `cfg.f` in a verified ctx. Defensively re-validates the shape so
     * a bug in an older signer can't leak garbage into the validator,
     * and produces the same UiFormSubmitConfig the render path would.
     *
     * @param mixed $wireFields
     *
     * @throws UiFieldValidationRuleException
     */
    public function parseSignedWire($wireFields): UiFormSubmitConfig
    {
        if (!is_array($wireFields) || !array_is_list($wireFields)) {
            throw new UiFieldValidationRuleException(
                'Signed form submit cfg.f must be a list of field definitions.',
            );
        }
        if (count($wireFields) > self::MAX_FIELDS) {
            throw new UiFieldValidationRuleException('Signed form submit cfg.f exceeds field count limit.');
        }

        $seen = [];
        $seenInstanceIds = [];
        $defs = [];
        foreach ($wireFields as $index => $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw new UiFieldValidationRuleException(sprintf(
                    'Signed form submit cfg.f[%d] must be an object.',
                    $index,
                ));
            }
            $name = $entry['n'] ?? null;
            if (!is_string($name) || preg_match(self::SAFE_IDENTIFIER, $name) !== 1) {
                throw new UiFieldValidationRuleException(sprintf(
                    'Signed form submit cfg.f[%d].n must be a safe identifier.',
                    $index,
                ));
            }
            if (isset($seen[$name])) {
                throw new UiFieldValidationRuleException(sprintf(
                    'Signed form submit cfg.f[%d] duplicates field name "%s".',
                    $index,
                    $name,
                ));
            }
            $seen[$name] = true;

            $rules = $entry['r'] ?? [];
            if (!is_array($rules) || !array_is_list($rules)) {
                throw new UiFieldValidationRuleException(sprintf(
                    'Signed form submit cfg.f[%d].r must be a list.',
                    $index,
                ));
            }
            foreach ($rules as $ri => $rule) {
                if (!is_array($rule) || !isset($rule['n']) || !is_string($rule['n'])) {
                    throw new UiFieldValidationRuleException(sprintf(
                        'Signed form submit cfg.f[%d].r[%d] is malformed.',
                        $index,
                        $ri,
                    ));
                }
            }
            /** @var list<array<string, mixed>> $rules */

            $label = null;
            if (isset($entry['l'])) {
                if (!is_string($entry['l'])) {
                    throw new UiFieldValidationRuleException(sprintf(
                        'Signed form submit cfg.f[%d].l must be a string.',
                        $index,
                    ));
                }
                $label = $entry['l'] !== '' ? $entry['l'] : null;
            }

            $required = !empty($entry['q']);

            $instanceId = null;
            if (array_key_exists('i', $entry)) {
                if (!UiInstanceIdGenerator::isSafe($entry['i'])) {
                    // Defence in depth: the parser already vetted the
                    // value at render time, so reaching here means a
                    // signer change or a tampered ctx that survived
                    // HMAC. Reject loudly — the dispatcher wraps it as
                    // a safe response without leaking the bad value.
                    throw new UiFieldValidationRuleException(sprintf(
                        'Signed form submit cfg.f[%d].i is not a safe instance id.',
                        $index,
                    ));
                }
                $instanceId = $entry['i'];
                /** @var string $instanceId */
                if (isset($seenInstanceIds[$instanceId])) {
                    throw new UiFieldValidationRuleException(sprintf(
                        'Signed form submit cfg.f[%d] duplicates instanceId "%s".',
                        $index,
                        $instanceId,
                    ));
                }
                $seenInstanceIds[$instanceId] = true;
            }

            $defs[] = new UiFormSubmitFieldDefinition(
                name: $name,
                rules: $rules,
                label: $label,
                required: $required,
                instanceId: $instanceId,
            );
        }
        return new UiFormSubmitConfig($defs);
    }
}
