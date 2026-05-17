<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Validation\DefaultUiFieldRuleRegistry;
use Semitexa\PlatformUi\Application\Service\Validation\Rule\MinLengthRule;
use Semitexa\PlatformUi\Application\Service\Validation\Rule\RequiredRule;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldRuleParser;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldRuleRegistryInterface;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationContext;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationRuleInterface;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidator;
use Semitexa\PlatformUi\Domain\Exception\UiFieldValidationRuleException;
use Semitexa\PlatformUi\Domain\Model\Event\UiFieldRuleSpec;
use Semitexa\PlatformUi\Domain\Model\Event\UiFieldValidationResult;

/**
 * Proves the extension seam end-to-end without shipping any extra
 * production rule. A SlugRule + AppFieldRuleRegistry fixture lives at
 * the bottom of this file (inlined per PSR-4 dev autoload limitations
 * in path-repo packages — see ref_framework_traps memory).
 *
 * Covers:
 *   - custom registry composes the default registry to inherit
 *     required / minLength / maxLength;
 *   - parser + validator end-to-end with the custom registry resolve
 *     slug rules;
 *   - unknown rule names fail safely even with the custom registry;
 *   - signed wire shape round-trips for custom rules (cfg.r can carry
 *     custom names — apps that bind a custom registry sign whatever
 *     names that registry knows).
 *
 * The slug rule itself is intentionally NOT a built-in. Apps that
 * want it provide their own equivalent and bind a custom registry.
 */
final class CustomRuleRegistryFixtureTest extends TestCase
{
    private function ctx(): UiFieldValidationContext
    {
        return new UiFieldValidationContext(
            componentName: 'platform.field',
            instanceId:    'uci_test',
            fieldName:     'input',
        );
    }

    private function customParser(): UiFieldRuleParser
    {
        return new UiFieldRuleParser(new AppFieldRuleRegistry());
    }

    #[Test]
    public function custom_registry_lists_built_ins_plus_slug(): void
    {
        $registry = new AppFieldRuleRegistry();
        $names = $registry->knownRuleNames();
        self::assertContains('required', $names);
        self::assertContains('minLength', $names);
        self::assertContains('maxLength', $names);
        self::assertContains('slug', $names);
    }

    #[Test]
    public function custom_registry_delegates_built_ins_to_default(): void
    {
        $registry = new AppFieldRuleRegistry();
        self::assertInstanceOf(
            RequiredRule::class,
            $registry->resolve(new UiFieldRuleSpec('required')),
        );
        self::assertInstanceOf(
            MinLengthRule::class,
            $registry->resolve(new UiFieldRuleSpec('minLength', [3])),
        );
    }

    #[Test]
    public function custom_registry_resolves_slug_rule(): void
    {
        $registry = new AppFieldRuleRegistry();
        $rule = $registry->resolve(new UiFieldRuleSpec('slug'));
        self::assertInstanceOf(SlugRule::class, $rule);
    }

    #[Test]
    public function slug_rule_passes_valid_slug(): void
    {
        $rule = new SlugRule();
        self::assertNull($rule->validate('hello-world', $this->ctx()));
        self::assertNull($rule->validate('abc', $this->ctx()));
        self::assertNull($rule->validate('platform-ui-2026', $this->ctx()));
    }

    #[Test]
    public function slug_rule_fails_invalid_slug(): void
    {
        $rule = new SlugRule();
        foreach (['Hello World', 'UPPER', '_leading_underscore', 'double--dash', '-leading-dash', 'trailing-', 'space inside'] as $bad) {
            $r = $rule->validate($bad, $this->ctx());
            self::assertNotNull($r, "Expected '{$bad}' to fail slug validation.");
            self::assertFalse($r->isValid());
            self::assertSame('Please enter a valid slug.', $r->message);
        }
    }

    #[Test]
    public function slug_rule_passes_empty_value_to_defer_to_required(): void
    {
        // Slug alone passes empty — pair with required to also reject
        // empties. Matches the same contract minLength uses.
        $rule = new SlugRule();
        self::assertNull($rule->validate('', $this->ctx()));
        self::assertNull($rule->validate("   ", $this->ctx()));
    }

    #[Test]
    public function parser_and_validator_end_to_end_with_custom_registry(): void
    {
        $parser = $this->customParser();

        // Empty: required fails first.
        $rules = $parser->resolveAll($parser->parseAll(['required', 'slug']));
        $r = (new UiFieldValidator())->validate('', $rules, $this->ctx());
        self::assertFalse($r->isValid());
        self::assertSame('This field is required.', $r->message);

        // Non-empty + invalid slug: required passes, slug fails.
        $r = (new UiFieldValidator())->validate('Bad Slug', $rules, $this->ctx());
        self::assertFalse($r->isValid());
        self::assertSame('Please enter a valid slug.', $r->message);

        // Valid slug: all rules pass.
        $r = (new UiFieldValidator())->validate('hello-world', $rules, $this->ctx());
        self::assertTrue($r->isValid());
        self::assertSame('Looks good.', $r->message);
    }

    #[Test]
    public function parser_with_custom_registry_round_trips_slug_through_wire_shape(): void
    {
        $parser = $this->customParser();
        $wire = $parser->parseAllToWire(['required', 'slug']);
        self::assertSame([
            ['n' => 'required'],
            ['n' => 'slug'],
        ], $wire);

        // Resolve back from the wire shape and verify rule types.
        $rules = $parser->resolveFromWire($wire);
        self::assertCount(2, $rules);
        self::assertInstanceOf(RequiredRule::class, $rules[0]);
        self::assertInstanceOf(SlugRule::class, $rules[1]);
    }

    #[Test]
    public function unknown_rule_still_fails_with_custom_registry(): void
    {
        // The custom registry adds 'slug' but does NOT add 'evilRule'.
        // Unknown names still surface as a typed exception.
        $parser = $this->customParser();
        $this->expectException(UiFieldValidationRuleException::class);
        $this->expectExceptionMessageMatches('/Unknown rule "evilRule"/');
        $parser->parseAllToWire(['evilRule']);
    }

    #[Test]
    public function default_parser_does_NOT_know_slug(): void
    {
        // Sanity: the default registry must not know 'slug' — that's
        // a fixture rule, never shipped as a built-in. Built explicitly
        // with DefaultUiFieldRuleRegistry now that the parser ctor
        // requires the registry argument (no silent fallback).
        $defaultParser = new UiFieldRuleParser(new DefaultUiFieldRuleRegistry());
        $this->expectException(UiFieldValidationRuleException::class);
        $defaultParser->parseAllToWire(['slug']);
    }

    #[Test]
    public function parser_registry_accessor_exposes_passed_registry(): void
    {
        // The registry the parser exposes is the one the caller passed
        // — verbatim, not a clone, not a wrapped instance. This is
        // what production callers rely on when they later need to
        // reach the same registry through `$parser->registry()`.
        $custom = new AppFieldRuleRegistry();
        $parser = new UiFieldRuleParser($custom);
        self::assertSame($custom, $parser->registry());

        $defaultRegistry = new DefaultUiFieldRuleRegistry();
        $defaultParser = new UiFieldRuleParser($defaultRegistry);
        self::assertSame($defaultRegistry, $defaultParser->registry());
    }
}

/**
 * Test fixture: lowercase-and-dashes slug rule. NOT a production
 * built-in. Implementations live under the test namespace so they
 * cannot accidentally leak into the framework.
 */
final class SlugRule implements UiFieldValidationRuleInterface
{
    public const NAME = 'slug';
    public const PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/';
    public const MESSAGE = 'Please enter a valid slug.';

    public function validate(mixed $value, UiFieldValidationContext $context): ?UiFieldValidationResult
    {
        $stringValue = is_scalar($value) ? (string) $value : '';
        $trimmed = trim($stringValue);
        if ($trimmed === '') {
            // Empty values pass — pair with `required` to reject them.
            return null;
        }
        if (preg_match(self::PATTERN, $trimmed) !== 1) {
            return UiFieldValidationResult::invalid(self::MESSAGE);
        }
        return null;
    }
}

/**
 * Test fixture: a custom registry composing DefaultUiFieldRuleRegistry
 * to inherit the three built-ins and adding `slug`. Matches the
 * documented override pattern from DefaultUiFieldRuleRegistry's
 * docblock — apps in production would mark a similar class with
 * #[SatisfiesServiceContract(of: UiFieldRuleRegistryInterface::class)].
 */
final class AppFieldRuleRegistry implements UiFieldRuleRegistryInterface
{
    private DefaultUiFieldRuleRegistry $builtins;

    public function __construct()
    {
        $this->builtins = new DefaultUiFieldRuleRegistry();
    }

    public function resolve(UiFieldRuleSpec $spec): UiFieldValidationRuleInterface
    {
        if ($spec->name === SlugRule::NAME) {
            if ($spec->params !== []) {
                throw new UiFieldValidationRuleException(
                    'Rule "slug" takes no parameters.',
                    $spec->name,
                );
            }
            return new SlugRule();
        }
        return $this->builtins->resolve($spec);
    }

    /** @return list<string> */
    public function knownRuleNames(): array
    {
        return [...$this->builtins->knownRuleNames(), SlugRule::NAME];
    }
}
