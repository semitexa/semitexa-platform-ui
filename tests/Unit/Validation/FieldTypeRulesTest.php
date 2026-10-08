<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Validation\DefaultUiFieldRuleRegistry;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiFieldRuleSpec;

/**
 * tk-rs-field-types: the rules field types lean on. Each passes an empty
 * value (`required` owns emptiness) and refuses what its type cannot hold.
 */
final class FieldTypeRulesTest extends TestCase
{
    /** @return iterable<string, array{string, list<scalar>, mixed, bool}> */
    public static function cases(): iterable
    {
        yield 'email ok' => ['email', [], 'a@example.test', true];
        yield 'email bad' => ['email', [], 'not-an-email', false];
        yield 'email empty' => ['email', [], '', true];
        yield 'url https' => ['url', [], 'https://example.test/a', true];
        yield 'url javascript' => ['url', [], 'javascript:alert(1)', false];
        yield 'url relative' => ['url', [], '/a', false];
        yield 'url empty' => ['url', [], '', true];
        yield 'integer ok' => ['integer', [], '-42', true];
        yield 'integer native' => ['integer', [], 7, true];
        yield 'integer decimal' => ['integer', [], '4.2', false];
        yield 'integer exponent' => ['integer', [], '1e3', false];
        yield 'number ok' => ['number', [], '12.50', true];
        yield 'number scale ok' => ['number', [2], '12.5', true];
        yield 'number scale over' => ['number', [2], '12.505', false];
        yield 'number comma' => ['number', [], '12,5', false];
        yield 'min ok' => ['min', [1], '1', true];
        yield 'min under' => ['min', [1], '0', false];
        yield 'min decimal' => ['min', ['0.5'], '0.4', false];
        yield 'min not a number' => ['min', [1], 'x', true];
        yield 'max ok' => ['max', [10], '10', true];
        yield 'max over' => ['max', [10], '11', false];
        yield 'slug ok' => ['slug', [], 'release-notes-2026', true];
        yield 'slug caps' => ['slug', [], 'Release-Notes', false];
        yield 'slug double hyphen' => ['slug', [], 'a--b', false];
        yield 'slug trailing hyphen' => ['slug', [], 'a-', false];
        yield 'in ok' => ['in', ['draft', 'published'], 'draft', true];
        yield 'in other' => ['in', ['draft', 'published'], 'archived', false];
        yield 'in empty' => ['in', ['draft'], '', true];
        yield 'in list ok' => ['in', ['a', 'b', 'c'], ['a', 'c'], true];
        yield 'in list one bad' => ['in', ['a', 'b'], ['a', 'x'], false];
        yield 'in list nested' => ['in', ['a'], [['a']], false];
        yield 'in int values' => ['in', [1, 2], '2', true];
    }

    #[Test]
    #[DataProvider('cases')]
    public function the_rule_accepts_what_its_type_holds(string $rule, array $params, mixed $value, bool $valid): void
    {
        $resolved = (new DefaultUiFieldRuleRegistry())->resolve(new UiFieldRuleSpec($rule, $params));
        $result = $resolved->validate($value, new UiFieldValidationContext('platform.field', 'i', 'f'));

        self::assertSame($valid, $result === null, sprintf('%s(%s) on %s', $rule, implode(',', $params), json_encode($value)));
    }

    #[Test]
    public function a_list_control_is_checked_value_by_value_not_as_its_joined_string(): void
    {
        // What the form really passes: the joined string, and the list beside it.
        // Checked as the string "tag-a,tag-b", two valid tags read as one
        // unknown value and every multi-tag save was refused.
        $in = (new DefaultUiFieldRuleRegistry())->resolve(new UiFieldRuleSpec('in', ['tag-a', 'tag-b', 'x,y']));
        $context = static fn (?array $list): UiFieldValidationContext => new UiFieldValidationContext('platform.form', 'i', 'tags', submittedList: $list);

        self::assertNull($in->validate('tag-a,tag-b', $context(['tag-a', 'tag-b'])));
        self::assertNotNull($in->validate('tag-a,nope', $context(['tag-a', 'nope'])));
        self::assertNull($in->validate('x,y', $context(null)), 'a single value may itself contain a comma');
    }

    #[Test]
    public function bad_parameters_are_refused_at_resolve(): void
    {
        $registry = new DefaultUiFieldRuleRegistry();
        foreach ([['min', ['x']], ['in', []], ['number', [20]], ['in', [['a']]]] as [$name, $params]) {
            try {
                $registry->resolve(new UiFieldRuleSpec($name, $params));
                self::fail("{$name} accepted bad parameters");
            } catch (\Throwable $e) {
                self::assertStringNotContainsString('accepted bad parameters', $e->getMessage());
            }
        }
    }
}
