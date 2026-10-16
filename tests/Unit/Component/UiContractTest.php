<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Component;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Attribute\UiSlot;
use Semitexa\PlatformUi\Domain\Model\Contract\UiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;
use Semitexa\Ssr\Attribute\AsComponent;

final class UiContractTest extends TestCase
{
    #[Test]
    public function schema_preserves_false_zero_null_and_nested_requirements(): void
    {
        $contract = new UiContract('Fixture', [
            new UiProp('enabled', UiPropType::Boolean, default: false),
            new UiProp('count', UiPropType::Integer, default: 0),
            new UiProp('optional', nullable: true),
            new UiProp('rows', UiPropType::Array, required: true, items: new UiProp('row', UiPropType::Object, properties: [
                new UiProp('label', required: true),
            ])),
        ]);
        self::assertSame(['enabled' => false, 'count' => 0, 'optional' => null], $contract->defaults());
        $schema = json_decode(json_encode($contract->schema(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['rows'], $schema['required']);
        self::assertSame(['label'], $schema['properties']['rows']['items']['required']);
        self::assertSame(false, $schema['properties']['rows']['items']['additionalProperties']);
    }

    #[Test]
    public function a_scalar_prop_is_spelled_out_as_json_schema_types(): void
    {
        $schema = (new UiContract('Fixture', [
            new UiProp('value', UiPropType::Scalar, nullable: true),
            new UiProp('pick', UiPropType::Scalar, default: 1),
        ]))->schema();

        self::assertSame(['string', 'number', 'boolean', 'null'], $schema['properties']->value['type']);
        self::assertSame(['string', 'number', 'boolean'], $schema['properties']->pick['type']);
        self::assertTrue(UiPropType::Scalar->accepts(false));
        self::assertFalse(UiPropType::Scalar->accepts([1]));
        self::assertFalse(UiPropType::Scalar->accepts(NAN));
    }

    #[Test]
    public function extra_props_open_the_schema_and_keep_examples_that_use_them(): void
    {
        $props = [new UiProp('title', nullable: true)];
        $example = new UiExample('edit', 'Edit', ['title' => 'Edit', 'articleId' => 7]);

        $closed = new UiContract('Closed', $props, [$example]);
        self::assertSame([], $closed->examples);
        self::assertStringContainsString('no prop named articleId', $closed->brokenExamples['edit']);
        self::assertFalse($closed->schema()['additionalProperties']);

        $open = new UiContract('Open', $props, [$example], extraProps: 'Context for the action.');
        self::assertSame(['edit'], array_keys($open->examples));
        self::assertSame(['description' => 'Context for the action.'], $open->schema()['additionalProperties']);
        // A declared prop is still checked in an open contract.
        $bad = new UiContract('Open', $props, [new UiExample('bad', 'Bad', ['title' => 3])], extraProps: 'Context.');
        self::assertArrayHasKey('bad', $bad->brokenExamples);
        // Dropping an example keeps the contract open.
        self::assertSame('Context for the action.', $open->withBrokenExample('edit', 'unknown slot')->extraProps);
    }

    #[Test]
    public function duplicate_props_are_rejected_instead_of_silently_overwriting(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new UiContract('Duplicate', [new UiProp('title'), new UiProp('title')]);
    }

    #[Test]
    public function invalid_defaults_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new UiProp('variant', default: 'typo', values: ['outlined', 'plain']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function examplesTheSchemaRejects(): iterable
    {
        yield 'unknown prop' => [['titel' => 'Example', 'rows' => []], 'no prop named titel'];
        yield 'value outside the enum' => [['variant' => 'typo', 'rows' => []], 'not one of the declared values'];
        yield 'wrong type' => [['count' => '3', 'rows' => []], 'must be of type integer'];
        yield 'missing required prop' => [[], 'missing required prop rows'];
        yield 'nested violation' => [['rows' => [['label' => 'ok'], ['lable' => 'x']]], 'props.rows[1] has no prop named lable'];
    }

    /** @param array<string, mixed> $props */
    #[Test]
    #[DataProvider('examplesTheSchemaRejects')]
    public function an_example_the_schema_would_reject_is_left_out_and_named(array $props, string $reason): void
    {
        // Contracts are built at registry boot: a throw here took the catalog
        // (or the worker) down for one bad example. The example is dropped and
        // named instead, and the strict check still refuses it.
        $contract = new UiContract('Fixture', self::exampleProps(), [
            new UiExample('good', 'Good', ['rows' => []]),
            new UiExample('bad', 'Bad', $props),
        ]);

        self::assertSame(['good'], array_keys($contract->examples));
        self::assertArrayHasKey('bad', $contract->brokenExamples);
        self::assertStringContainsString($reason, $contract->brokenExamples['bad']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('UI example bad: ');
        $contract->assertExamplesValid();
    }

    #[Test]
    public function a_duplicate_or_foreign_example_is_left_out_and_named(): void
    {
        $contract = new UiContract('Fixture', self::exampleProps(), [
            new UiExample('one', 'One', ['rows' => []]),
            new UiExample('one', 'One again', ['rows' => []]),
            'not an example',
        ]);

        self::assertSame(['one'], array_keys($contract->examples));
        self::assertSame(['one' => 'is declared more than once', '#2' => 'is not a UiExample declaration'], $contract->brokenExamples);
    }

    #[Test]
    public function an_example_the_schema_accepts_is_kept(): void
    {
        $contract = new UiContract('Fixture', self::exampleProps(), [
            new UiExample('good', 'Good', ['variant' => 'plain', 'count' => 3, 'rows' => [['label' => 'One']]]),
        ]);
        self::assertSame(['good'], array_keys($contract->examples));
    }

    #[Test]
    public function numbers_are_compared_the_way_json_schema_compares_them(): void
    {
        $contract = new UiContract('Fixture', [
            new UiProp('count', UiPropType::Integer, default: 0),
            new UiProp('scale', UiPropType::Number, default: 1, values: [1, 2.5]),
            new UiProp('label', default: '1', values: ['1']),
        ], [new UiExample('integral', 'Integral', ['count' => 3.0, 'scale' => 1.0])]);
        self::assertSame(['integral'], array_keys($contract->examples));

        foreach ([['count' => 3.5], ['scale' => 2], ['label' => 1]] as $props) {
            $rejected = new UiContract('Fixture', array_values($contract->props), [new UiExample('bad', 'Bad', $props)]);
            self::assertArrayHasKey('bad', $rejected->brokenExamples, 'Accepted ' . json_encode($props));
        }
    }

    #[Test]
    public function an_empty_object_value_is_encoded_as_a_json_object(): void
    {
        $contract = new UiContract('Fixture', [
            new UiProp('config', UiPropType::Object, default: []),
            new UiProp('rows', UiPropType::Array, items: new UiProp('row', UiPropType::Object), default: [[]]),
        ], [new UiExample('empty', 'Empty', ['config' => []])]);

        self::assertStringContainsString('"config":{"type":"object","default":{}}', json_encode($contract->schema(), JSON_THROW_ON_ERROR));
        self::assertSame('{"config":{},"rows":[{}]}', json_encode($contract->defaults(), JSON_THROW_ON_ERROR));
        self::assertSame('{"config":{}}', json_encode($contract->exampleArray($contract->examples['empty'])['props'], JSON_THROW_ON_ERROR));
    }

    /** @return list<UiProp> */
    private static function exampleProps(): array
    {
        return [
            new UiProp('variant', default: 'elevated', values: ['elevated', 'plain']),
            new UiProp('count', UiPropType::Integer, default: 0),
            new UiProp('rows', UiPropType::Array, required: true, items: new UiProp('row', UiPropType::Object, properties: [
                new UiProp('label', required: true),
            ])),
        ];
    }

    #[Test]
    public function metadata_reads_contract_without_instantiating_component(): void
    {
        $metadata = (new UiComponentMetadataFactory())->fromClass(ContractFixture::class);
        self::assertSame('Fixture', $metadata->contract?->summary);
        self::assertSame('Example', $metadata->contract?->examples['default']->slots['body']);
    }

    #[Test]
    public function old_components_remain_untyped(): void
    {
        $metadata = (new UiComponentMetadataFactory())->fromClass(LegacyContractFixture::class);
        self::assertNull($metadata->contract);
    }

    #[Test]
    public function an_example_naming_a_slot_the_component_does_not_have_is_left_out(): void
    {
        $metadata = (new UiComponentMetadataFactory())->fromClass(InvalidContractFixture::class);

        self::assertNotNull($metadata->contract);
        self::assertArrayNotHasKey('default', $metadata->contract->examples);
        self::assertStringContainsString('uses unknown slot', $metadata->contract->brokenExamples['default'] ?? '');
    }
}

#[AsComponent(name: 'contract.fixture')]
#[UiSlot(name: 'body')]
#[AsUiContract('Fixture', props: [new UiProp('title')], examples: [new UiExample('default', 'Default', slots: ['body' => 'Example'])])]
final class ContractFixture
{
    public function __construct() { throw new \LogicException('Metadata must never execute components.'); }
}

#[AsComponent(name: 'contract.legacy')]
#[UiSlot(name: 'body')]
final class LegacyContractFixture {}

#[AsComponent(name: 'contract.invalid')]
#[UiSlot(name: 'body')]
#[AsUiContract('Invalid', examples: [new UiExample('default', 'Default', slots: ['typo' => 'bad'])])]
final class InvalidContractFixture {}
