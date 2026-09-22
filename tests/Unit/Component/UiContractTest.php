<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Component;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Attribute\UiSlot;
use Semitexa\PlatformUi\Domain\Exception\UiComponentRegistryException;
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
    public function example_cannot_reference_a_slot_the_component_does_not_have(): void
    {
        $this->expectException(UiComponentRegistryException::class);
        (new UiComponentMetadataFactory())->fromClass(InvalidContractFixture::class);
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
