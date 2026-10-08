<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Validation;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\PlatformUi\Application\Service\Validation\Rule\EmailRule;
use Semitexa\PlatformUi\Application\Service\Validation\Rule\InRule;
use Semitexa\PlatformUi\Application\Service\Validation\Rule\IntegerRule;
use Semitexa\PlatformUi\Application\Service\Validation\Rule\MaxLengthRule;
use Semitexa\PlatformUi\Application\Service\Validation\Rule\MaxRule;
use Semitexa\PlatformUi\Application\Service\Validation\Rule\MinRule;
use Semitexa\PlatformUi\Application\Service\Validation\Rule\NumberRule;
use Semitexa\PlatformUi\Application\Service\Validation\Rule\SlugRule;
use Semitexa\PlatformUi\Application\Service\Validation\Rule\UrlRule;
use Semitexa\PlatformUi\Application\Service\Validation\Rule\MinLengthRule;
use Semitexa\PlatformUi\Application\Service\Validation\Rule\RequiredRule;
use Semitexa\PlatformUi\Application\Service\Validation\Rule\SameAsFieldRule;
use Semitexa\PlatformUi\Domain\Exception\UiFieldValidationRuleException;
use Semitexa\PlatformUi\Domain\Model\Event\UiFieldRuleSpec;

/**
 * Default rule registry — knows the four built-in rules:
 *
 *   - `required`     → RequiredRule
 *   - `minLength`    → MinLengthRule(int min ≥ 0)
 *   - `maxLength`    → MaxLengthRule(int max ≥ 0)
 *   - `sameAsField`  → SameAsFieldRule(string siblingFieldName, ?string customMessage)
 *
 * `sameAsField` is the first cross-field built-in. It compares the
 * current field's value against a sibling field's value read from
 * the sanitised `payload.form.values` snapshot (see
 * UiFormPayloadSnapshot). Snapshot values are client-submitted and
 * UX-feedback-only; the final submit pipeline (future slice) must
 * revalidate.
 *
 * Bound as the default UiFieldRuleRegistryInterface implementation via
 * SatisfiesServiceContract. Apps override by declaring their own
 * implementation in a module that "extends" semitexa-platform-ui:
 *
 *     #[SatisfiesServiceContract(of: UiFieldRuleRegistryInterface::class)]
 *     final class AppFieldRuleRegistry implements UiFieldRuleRegistryInterface
 *     {
 *         private DefaultUiFieldRuleRegistry $builtins;
 *
 *         public function __construct()
 *         {
 *             // Compose with the default to inherit built-ins.
 *             $this->builtins = new DefaultUiFieldRuleRegistry();
 *         }
 *
 *         public function resolve(UiFieldRuleSpec $spec): UiFieldValidationRuleInterface
 *         {
 *             return match ($spec->name) {
 *                 'slug'   => new SlugRule(),
 *                 'domain' => new DomainRule(),
 *                 default  => $this->builtins->resolve($spec),
 *             };
 *         }
 *
 *         public function knownRuleNames(): array
 *         {
 *             return [...$this->builtins->knownRuleNames(), 'slug', 'domain'];
 *         }
 *     }
 *
 * The class uses a fixed `match` expression on `$spec->name` — it
 * NEVER reflects a class FQCN out of the rule name. This is the
 * security-perimeter contract custom registries must also honour.
 */
#[SatisfiesServiceContract(of: UiFieldRuleRegistryInterface::class)]
final class DefaultUiFieldRuleRegistry implements UiFieldRuleRegistryInterface
{
    /**
     * Per-rule parameter arity bounds `[min, max]`. Inclusive on both
     * ends. The registry — not the parser — owns the contract because
     * arity is per-rule semantic; the parser only knows DSL structure.
     *
     * Variadic-bounded entries (e.g. `[1, 2]` for sameAsField) keep
     * the existing fixed-arity built-ins exactly as strict as before.
     *
     * @var array<string, array{int, int}>
     */
    private const RULE_PARAM_ARITY = [
        RequiredRule::NAME    => [0, 0],
        MinLengthRule::NAME   => [1, 1],
        MaxLengthRule::NAME   => [1, 1],
        SameAsFieldRule::NAME => [1, 2],
        EmailRule::NAME       => [0, 0],
        UrlRule::NAME         => [0, 0],
        IntegerRule::NAME     => [0, 0],
        NumberRule::NAME      => [0, 1],
        MinRule::NAME         => [1, 1],
        MaxRule::NAME         => [1, 1],
        SlugRule::NAME        => [0, 0],
        InRule::NAME          => [1, 500],
    ];

    /** Same shape as SameAsFieldRule::SAFE_IDENTIFIER — kept here to avoid coupling. */
    private const SAFE_IDENTIFIER = '/\A[A-Za-z_][A-Za-z0-9_-]*\z/';

    public function resolve(UiFieldRuleSpec $spec): UiFieldValidationRuleInterface
    {
        $this->assertKnown($spec);
        $this->assertParamCount($spec);
        try {
            return match ($spec->name) {
                RequiredRule::NAME    => new RequiredRule(),
                MinLengthRule::NAME   => new MinLengthRule($this->intParam($spec, 0)),
                MaxLengthRule::NAME   => new MaxLengthRule($this->intParam($spec, 0)),
                SameAsFieldRule::NAME => $this->buildSameAsField($spec),
                EmailRule::NAME       => new EmailRule(),
                UrlRule::NAME         => new UrlRule(),
                IntegerRule::NAME     => new IntegerRule(),
                NumberRule::NAME      => new NumberRule($spec->params === [] ? null : $this->intParam($spec, 0)),
                MinRule::NAME         => new MinRule($this->numParam($spec, 0)),
                MaxRule::NAME         => new MaxRule($this->numParam($spec, 0)),
                SlugRule::NAME        => new SlugRule(),
                InRule::NAME          => new InRule($this->scalarParams($spec)),
            };
        } catch (\InvalidArgumentException $e) {
            // Rule constructor rejected its parameters. Wrap as a typed
            // domain exception so the parser path keeps the same
            // failure model.
            throw new UiFieldValidationRuleException($e->getMessage(), $spec->name);
        }
    }

    /** @return list<string> */
    public function knownRuleNames(): array
    {
        return array_keys(self::RULE_PARAM_ARITY);
    }

    private function assertKnown(UiFieldRuleSpec $spec): void
    {
        if (!array_key_exists($spec->name, self::RULE_PARAM_ARITY)) {
            throw new UiFieldValidationRuleException(sprintf(
                'Unknown rule "%s". Known rules: %s.',
                $spec->name,
                implode(', ', $this->knownRuleNames()),
            ), $spec->name);
        }
    }

    private function assertParamCount(UiFieldRuleSpec $spec): void
    {
        [$min, $max] = self::RULE_PARAM_ARITY[$spec->name];
        $count = count($spec->params);
        if ($count < $min || $count > $max) {
            throw new UiFieldValidationRuleException(sprintf(
                'Rule "%s" expects %s parameter(s), got %d.',
                $spec->name,
                $min === $max ? (string) $min : "{$min}–{$max}",
                $count,
            ), $spec->name);
        }
    }

    private function buildSameAsField(UiFieldRuleSpec $spec): SameAsFieldRule
    {
        $siblingFieldName = $spec->params[0] ?? null;
        if (!is_string($siblingFieldName) || preg_match(self::SAFE_IDENTIFIER, $siblingFieldName) !== 1) {
            throw new UiFieldValidationRuleException(
                'Rule "sameAsField" parameter 0 must be a safe field-name identifier ([A-Za-z_][A-Za-z0-9_-]*).',
                $spec->name,
            );
        }
        if (!array_key_exists(1, $spec->params)) {
            return new SameAsFieldRule($siblingFieldName);
        }
        $message = $spec->params[1];
        if (!is_string($message) || $message === '') {
            throw new UiFieldValidationRuleException(
                'Rule "sameAsField" parameter 1 (custom message) must be a non-empty string.',
                $spec->name,
            );
        }
        return new SameAsFieldRule($siblingFieldName, $message);
    }

    private function numParam(UiFieldRuleSpec $spec, int $index): int|float
    {
        $value = $spec->params[$index] ?? null;
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A-?\d+(?:\.\d+)?\z/', $value) === 1) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }
        throw new UiFieldValidationRuleException(sprintf(
            'Rule "%s" parameter %d must be a number; got %s.',
            $spec->name,
            $index,
            get_debug_type($value),
        ), $spec->name);
    }

    /** @return list<string|int> */
    private function scalarParams(UiFieldRuleSpec $spec): array
    {
        $values = [];
        foreach ($spec->params as $index => $value) {
            if (!is_string($value) && !is_int($value)) {
                throw new UiFieldValidationRuleException(sprintf(
                    'Rule "%s" parameter %d must be a string or an integer; got %s.',
                    $spec->name,
                    $index,
                    get_debug_type($value),
                ), $spec->name);
            }
            $values[] = $value;
        }

        return $values;
    }

    private function intParam(UiFieldRuleSpec $spec, int $index): int
    {
        $value = $spec->params[$index] ?? null;
        if (is_int($value)) {
            return $value;
        }
        // JSON round-trip may stringify ints. Accept digit-only
        // strings to keep the wire shape forgiving.
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }
        throw new UiFieldValidationRuleException(sprintf(
            'Rule "%s" parameter %d must be an integer; got %s.',
            $spec->name,
            $index,
            get_debug_type($value),
        ), $spec->name);
    }
}
