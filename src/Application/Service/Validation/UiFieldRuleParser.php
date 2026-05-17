<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Validation;

use Semitexa\PlatformUi\Domain\Exception\UiFieldValidationRuleException;
use Semitexa\PlatformUi\Domain\Model\Event\UiFieldRuleSpec;

/**
 * Translates the developer-facing rule spec DSL into safe internal
 * shapes.
 *
 * Responsibility split (this slice):
 *   - **Parser** (this class): structural / syntactic DSL validation.
 *     Rejects non-list outer shape, non-string rule names, non-scalar
 *     parameters, closures, callables, objects, class FQCNs. This is
 *     the security perimeter for the DSL surface and stays here even
 *     after the registry was extracted.
 *   - **Registry** (UiFieldRuleRegistryInterface): name → rule-object
 *     mapping. Owns per-rule parameter contracts (count + type) and
 *     constructs the rule instances. Apps override by binding a
 *     custom registry via SatisfiesServiceContract.
 *
 * Dependency rule (post-cleanup):
 *   The registry is a REQUIRED constructor argument. The parser does
 *   NOT silently fall back to `new DefaultUiFieldRuleRegistry()` and
 *   does NOT reach into the static `UiFieldRuleRegistry::getActive()`
 *   holder. Callers that want the active registry MUST pass it in
 *   explicitly — this prevents an incorrectly wired application from
 *   silently validating against the wrong rule set (the most likely
 *   failure mode if the implicit-fallback default went unmaintained).
 *   Production callers — FieldComponent, FormComponent, the
 *   ui_field_rules / ui_form_resolve_submit_fields Twig helpers,
 *   UiFormSubmitConfigParser — all pass an explicit registry today.
 *
 * The DSL surface itself is unchanged:
 *
 *   1. String form: `'required'` for parameterless rules.
 *   2. Array form: `['minLength', 3]` for parametrized rules — name
 *      first, then positional scalar params.
 *
 * Output:
 *   - parseAll(array): list<UiFieldRuleSpec>        — structural-only;
 *     does NOT validate rule names against the registry.
 *   - parseAllToWire(array): list<array>            — parseAll() THEN
 *     registry.resolve() per spec (so unknown names + bad params
 *     fail at render time) THEN emits JSON wire shape for signing
 *     into ctx.
 *   - resolveAll(list<UiFieldRuleSpec>): list<UiFieldValidationRuleInterface>
 *     — delegates to registry.
 *   - resolveFromWire(array): list<UiFieldValidationRuleInterface>
 *     — structural check on wire shape + delegates to registry.
 */
final class UiFieldRuleParser
{
    public function __construct(
        private readonly UiFieldRuleRegistryInterface $registry,
    ) {}

    /**
     * Parse a list of raw specs into normalized UiFieldRuleSpec
     * objects. Only structural / syntactic checks run — rule names
     * are NOT validated against the registry here. Use
     * parseAllToWire() at render time to get the registry-validated
     * + wire-shaped output ready for signing.
     *
     * @param array<int, mixed> $rawSpecs
     * @return list<UiFieldRuleSpec>
     *
     * @throws UiFieldValidationRuleException
     */
    public function parseAll(array $rawSpecs): array
    {
        if (!array_is_list($rawSpecs)) {
            throw new UiFieldValidationRuleException(
                'Rule spec list must be a plain array (no string keys).',
            );
        }
        $out = [];
        foreach ($rawSpecs as $index => $rawSpec) {
            $out[] = $this->parseOne($rawSpec, $index);
        }
        return $out;
    }

    /**
     * parseAll() + registry.resolve() (to validate names + params at
     * render time) + emit the compact JSON shape ready to be signed
     * into the event manifest's ctx claim. The render-time validation
     * is the security perimeter that catches unknown rule names
     * BEFORE they hit the signed wire, so a typo or a deliberate
     * smuggling attempt fails at template compile rather than at
     * dispatch.
     *
     * @param array<int, mixed> $rawSpecs
     * @return list<array{n: string, p?: list<scalar>}>
     *
     * @throws UiFieldValidationRuleException
     */
    public function parseAllToWire(array $rawSpecs): array
    {
        $specs = $this->parseAll($rawSpecs);
        $out = [];
        foreach ($specs as $spec) {
            // Validate via the registry. The rule object is thrown
            // away — we only needed the validation side effect.
            $this->registry->resolve($spec);
            $out[] = $spec->toWireShape();
        }
        return $out;
    }

    /**
     * Convert a list of normalized specs into rule objects via the
     * registry. The registry owns per-rule parameter contracts and
     * throws on unknown / malformed specs.
     *
     * @param list<UiFieldRuleSpec> $specs
     * @return list<UiFieldValidationRuleInterface>
     *
     * @throws UiFieldValidationRuleException
     */
    public function resolveAll(array $specs): array
    {
        $out = [];
        foreach ($specs as $spec) {
            $out[] = $this->registry->resolve($spec);
        }
        return $out;
    }

    /**
     * Reverse of parseAllToWire(): take the array of `{n,p?}` maps
     * read out of a signed ctx, structurally validate them, and
     * delegate to the registry to instantiate the rules. Used by the
     * dispatcher / handler path.
     *
     * @param array<int, array{n?: mixed, p?: mixed}> $wireSpecs
     * @return list<UiFieldValidationRuleInterface>
     *
     * @throws UiFieldValidationRuleException
     */
    public function resolveFromWire(array $wireSpecs): array
    {
        $specs = [];
        foreach ($wireSpecs as $index => $wire) {
            if (!is_array($wire) || !isset($wire['n']) || !is_string($wire['n'])) {
                throw new UiFieldValidationRuleException(sprintf(
                    'Rule spec %d in signed ctx is missing the "n" key.',
                    $index,
                ));
            }
            $params = [];
            if (isset($wire['p'])) {
                if (!is_array($wire['p']) || !array_is_list($wire['p'])) {
                    throw new UiFieldValidationRuleException(sprintf(
                        'Rule spec %d in signed ctx has a malformed "p" key.',
                        $index,
                    ), $wire['n']);
                }
                foreach ($wire['p'] as $p) {
                    if (!is_scalar($p)) {
                        throw new UiFieldValidationRuleException(sprintf(
                            'Rule spec %d in signed ctx has a non-scalar parameter.',
                            $index,
                        ), $wire['n']);
                    }
                    $params[] = $p;
                }
            }
            $specs[] = new UiFieldRuleSpec($wire['n'], $params);
        }
        return $this->resolveAll($specs);
    }

    /** @return list<string> */
    public function knownRuleNames(): array
    {
        return $this->registry->knownRuleNames();
    }

    public function registry(): UiFieldRuleRegistryInterface
    {
        return $this->registry;
    }

    private function parseOne(mixed $rawSpec, int $index): UiFieldRuleSpec
    {
        if (is_string($rawSpec)) {
            return new UiFieldRuleSpec($rawSpec, []);
        }
        if (!is_array($rawSpec)) {
            throw new UiFieldValidationRuleException(sprintf(
                'Rule spec at index %d must be a string or an array, got %s.',
                $index,
                get_debug_type($rawSpec),
            ));
        }
        if (!array_is_list($rawSpec)) {
            throw new UiFieldValidationRuleException(sprintf(
                'Rule spec array at index %d must be a list. Use the '
                . '[name, param1, param2, …] shape.',
                $index,
            ));
        }
        if ($rawSpec === []) {
            throw new UiFieldValidationRuleException(sprintf(
                'Rule spec at index %d is an empty array.',
                $index,
            ));
        }
        $name = $rawSpec[0];
        if (!is_string($name) || $name === '') {
            throw new UiFieldValidationRuleException(sprintf(
                'Rule spec at index %d has a non-string rule name.',
                $index,
            ));
        }
        $params = array_slice($rawSpec, 1);
        foreach ($params as $paramIndex => $param) {
            if (!is_scalar($param)) {
                throw new UiFieldValidationRuleException(sprintf(
                    'Rule "%s" parameter %d must be scalar; got %s. '
                    . 'Callables, service names, and class FQCNs are '
                    . 'forbidden in rule specs.',
                    $name,
                    $paramIndex,
                    get_debug_type($param),
                ), $name);
            }
        }
        /** @var list<scalar> $params */
        return new UiFieldRuleSpec($name, $params);
    }
}
