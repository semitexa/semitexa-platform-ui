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
    /** The longest message a field may give one of its rules. */
    public const MAX_MESSAGE_LENGTH = 500;

    /**
     * All of one field's messages together, in bytes. They ride in the signed
     * ctx twice (each rule's `m` and the field's props), and props past the
     * manifest's 4096-byte budget are dropped, so the field would re-render
     * from incomplete props.
     */
    public const MAX_MESSAGES_BYTES = 2048;

    /**
     * Not a rule: the field's own text for a value that passed every rule
     * (in place of "Looks good."). Signed into the field's cfg, not a spec.
     */
    public const VALID_MESSAGE_KEY = 'valid';

    public function __construct(
        private readonly UiFieldRuleRegistryInterface $registry,
    ) {}

    /**
     * Give each rule of a field the field's own message: `messages` is
     * `{rule name: text}`, the text goes into that rule's wire spec as `m`,
     * and validation shows it instead of the rule's built-in English one.
     * Signed with the rules, so the field config, the autoFields marker and
     * the form's cfg.f all carry it, and the client cannot change it.
     *
     * Before this a field could not say "Обов'язкове поле": a live run
     * (2026-11-07) wrote its own rule registry to get Ukrainian errors.
     * The app passes the text already translated (`trans()` in its template).
     *
     * A message for a rule the field does not have is refused, as a typo
     * that would otherwise show the English text with no sign why.
     *
     * @param list<array<string, mixed>> $wire specs from parseAllToWire()
     * @param array<mixed> $messages
     * @return list<array<string, mixed>>
     *
     * @throws UiFieldValidationRuleException
     */
    public static function withMessages(array $wire, array $messages): array
    {
        if ($messages === []) {
            return $wire;
        }
        if (array_is_list($messages)) {
            throw new UiFieldValidationRuleException('Field `messages` must map rule names to messages, as {required: "…"}.');
        }
        $names = array_values(array_filter(array_map(static fn (array $spec): mixed => $spec['n'] ?? null, $wire), 'is_string'));
        $bytes = 0;
        foreach ($messages as $rule => $message) {
            $rule = (string) $rule;
            self::assertMessage($rule, $message, $rule === self::VALID_MESSAGE_KEY);
            /** @var string $message */
            $bytes += strlen($message);
            if ($bytes > self::MAX_MESSAGES_BYTES) {
                throw new UiFieldValidationRuleException(sprintf('Field `messages` exceed %d bytes together.', self::MAX_MESSAGES_BYTES), $rule);
            }
            if ($rule !== self::VALID_MESSAGE_KEY && !in_array($rule, $names, true)) {
                throw new UiFieldValidationRuleException(sprintf(
                    'Field `messages` names rule "%s", which the field does not have (rules: %s).',
                    $rule,
                    $names === [] ? 'none' : implode(', ', $names),
                ), $rule);
            }
        }
        foreach ($wire as $index => $spec) {
            $name = $spec['n'] ?? null;
            if (is_string($name) && is_string($messages[$name] ?? null)) {
                $wire[$index]['m'] = $messages[$name];
            }
        }

        return $wire;
    }

    /**
     * The field's own message for each rule, in rule order; null where the
     * rule keeps its built-in one.
     *
     * @param array<int, mixed> $wire
     * @return list<?string>
     */
    public static function messagesOf(array $wire): array
    {
        return array_map(
            static fn (mixed $spec): ?string => is_array($spec) && is_string($spec['m'] ?? null) && $spec['m'] !== '' ? $spec['m'] : null,
            array_values($wire),
        );
    }

    /**
     * @throws UiFieldValidationRuleException
     */
    private static function assertMessage(string $rule, mixed $message, bool $mayBeEmpty): void
    {
        if (!is_string($message) || (!$mayBeEmpty && trim($message) === '')) {
            throw new UiFieldValidationRuleException(sprintf('Field `messages.%s` must be a non-empty string.', $rule), $rule);
        }
        if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            throw new UiFieldValidationRuleException(sprintf('Field `messages.%s` exceeds %d characters.', $rule, self::MAX_MESSAGE_LENGTH), $rule);
        }
    }

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
