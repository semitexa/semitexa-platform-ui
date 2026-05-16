<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Validation\Rule\MaxLengthRule;
use Semitexa\PlatformUi\Application\Service\Validation\Rule\MinLengthRule;
use Semitexa\PlatformUi\Application\Service\Validation\Rule\RequiredRule;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldRuleParser;
use Semitexa\PlatformUi\Domain\Exception\UiFieldValidationRuleException;
use Semitexa\PlatformUi\Domain\Model\Event\UiFieldRuleSpec;

final class UiFieldRuleParserTest extends TestCase
{
    #[Test]
    public function parses_required_string_form(): void
    {
        $specs = (new UiFieldRuleParser())->parseAll(['required']);
        self::assertCount(1, $specs);
        self::assertInstanceOf(UiFieldRuleSpec::class, $specs[0]);
        self::assertSame('required', $specs[0]->name);
        self::assertSame([], $specs[0]->params);
    }

    #[Test]
    public function parses_min_length_array_form(): void
    {
        $specs = (new UiFieldRuleParser())->parseAll([['minLength', 3]]);
        self::assertSame('minLength', $specs[0]->name);
        self::assertSame([3], $specs[0]->params);
    }

    #[Test]
    public function parses_max_length_array_form(): void
    {
        $specs = (new UiFieldRuleParser())->parseAll([['maxLength', 20]]);
        self::assertSame('maxLength', $specs[0]->name);
        self::assertSame([20], $specs[0]->params);
    }

    #[Test]
    public function parses_mixed_list(): void
    {
        $specs = (new UiFieldRuleParser())->parseAll([
            'required',
            ['minLength', 3],
            ['maxLength', 50],
        ]);
        self::assertCount(3, $specs);
        self::assertSame(['required', 'minLength', 'maxLength'], array_map(fn ($s) => $s->name, $specs));
    }

    #[Test]
    public function rejects_unknown_rule_name(): void
    {
        // Registry-owned check: unknown names surface at the
        // parseAllToWire / resolveAll boundary, not at structural
        // parseAll() (which now stays syntax-only).
        $this->expectException(UiFieldValidationRuleException::class);
        $this->expectExceptionMessageMatches('/Unknown rule "evilRule"/');
        (new UiFieldRuleParser())->parseAllToWire(['evilRule']);
    }

    #[Test]
    public function rejects_wrong_param_count_for_min_length(): void
    {
        $this->expectException(UiFieldValidationRuleException::class);
        $this->expectExceptionMessageMatches('/expects 1 parameter/');
        (new UiFieldRuleParser())->parseAllToWire([['minLength']]);
    }

    #[Test]
    public function rejects_extra_params_for_required(): void
    {
        $this->expectException(UiFieldValidationRuleException::class);
        (new UiFieldRuleParser())->parseAllToWire([['required', 'extra']]);
    }

    #[Test]
    public function structural_parse_all_passes_unknown_rule_names(): void
    {
        // Pin the new responsibility split: parseAll() does syntax
        // only and does NOT consult the registry. An unknown name
        // round-trips through parseAll() unblocked — failure must
        // come at the wire-shape boundary.
        $specs = (new UiFieldRuleParser())->parseAll(['evilRule']);
        self::assertCount(1, $specs);
        self::assertSame('evilRule', $specs[0]->name);
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function unsafeParams(): iterable
    {
        yield 'closure' => [['minLength', static fn () => 3]];
        yield 'object'  => [['minLength', new \stdClass()]];
        yield 'array'   => [['minLength', [1, 2, 3]]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeParams')]
    #[Test]
    public function rejects_non_scalar_params(mixed $rawSpec): void
    {
        $this->expectException(UiFieldValidationRuleException::class);
        $this->expectExceptionMessageMatches('/must be scalar/');
        (new UiFieldRuleParser())->parseAll([$rawSpec]);
    }

    #[Test]
    public function rejects_empty_array_spec(): void
    {
        $this->expectException(UiFieldValidationRuleException::class);
        (new UiFieldRuleParser())->parseAll([[]]);
    }

    #[Test]
    public function rejects_non_list_inner_spec(): void
    {
        $this->expectException(UiFieldValidationRuleException::class);
        (new UiFieldRuleParser())->parseAll([['name' => 'required']]);
    }

    #[Test]
    public function rejects_non_string_rule_name(): void
    {
        $this->expectException(UiFieldValidationRuleException::class);
        (new UiFieldRuleParser())->parseAll([[42, 'arg']]);
    }

    #[Test]
    public function rejects_top_level_associative_array(): void
    {
        $this->expectException(UiFieldValidationRuleException::class);
        (new UiFieldRuleParser())->parseAll(['a' => 'required']);
    }

    #[Test]
    public function parse_all_to_wire_returns_compact_shape(): void
    {
        $wire = (new UiFieldRuleParser())->parseAllToWire([
            'required',
            ['minLength', 3],
        ]);
        self::assertSame([
            ['n' => 'required'],
            ['n' => 'minLength', 'p' => [3]],
        ], $wire);
    }

    #[Test]
    public function resolve_all_returns_rule_objects(): void
    {
        $parser = new UiFieldRuleParser();
        $rules = $parser->resolveAll($parser->parseAll([
            'required',
            ['minLength', 3],
            ['maxLength', 20],
        ]));
        self::assertCount(3, $rules);
        self::assertInstanceOf(RequiredRule::class, $rules[0]);
        self::assertInstanceOf(MinLengthRule::class, $rules[1]);
        self::assertInstanceOf(MaxLengthRule::class, $rules[2]);
    }

    #[Test]
    public function resolve_from_wire_round_trips(): void
    {
        $parser = new UiFieldRuleParser();
        $wire = $parser->parseAllToWire(['required', ['minLength', 3]]);
        $rules = $parser->resolveFromWire($wire);
        self::assertCount(2, $rules);
        self::assertInstanceOf(RequiredRule::class, $rules[0]);
        self::assertInstanceOf(MinLengthRule::class, $rules[1]);
    }

    #[Test]
    public function resolve_from_wire_rejects_malformed_wire(): void
    {
        $this->expectException(UiFieldValidationRuleException::class);
        (new UiFieldRuleParser())->resolveFromWire([['no-n-key' => true]]);
    }

    #[Test]
    public function resolve_from_wire_rejects_non_scalar_param(): void
    {
        $this->expectException(UiFieldValidationRuleException::class);
        (new UiFieldRuleParser())->resolveFromWire([
            ['n' => 'minLength', 'p' => [['not', 'scalar']]],
        ]);
    }

    #[Test]
    public function known_rule_names_are_documented(): void
    {
        // Regression: docs + playground copy quote these names verbatim.
        self::assertSame(
            ['required', 'minLength', 'maxLength', 'sameAsField'],
            (new UiFieldRuleParser())->knownRuleNames(),
        );
    }
}
