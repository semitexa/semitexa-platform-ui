<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Catalog;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Component\Builtin\CardComponent;
use Semitexa\PlatformUi\Application\Component\Builtin\FieldComponent;
use Semitexa\PlatformUi\Application\Service\Behavior\Builtin\DropdownBehavior;
use Semitexa\PlatformUi\Application\Service\Behavior\UiBehaviorCatalog;
use Semitexa\PlatformUi\Application\Service\Behavior\UiBehaviorMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Catalog\UiCatalogProjector;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentCatalog;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\InputPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveCatalog;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveMetadataFactory;
use Semitexa\Testing\Traits\BuildsContainerManagedObjects;

final class UiCatalogProjectorTest extends TestCase
{
    use BuildsContainerManagedObjects;

    private function projector(): UiCatalogProjector
    {
        $primitives = new UiPrimitiveCatalog();
        $primitives->register((new UiPrimitiveMetadataFactory())->fromClass(InputPrimitive::class));
        $components = new UiComponentCatalog();
        foreach ([CardComponent::class, FieldComponent::class] as $class) {
            $components->register((new UiComponentMetadataFactory())->fromClass($class));
        }
        $behaviors = new UiBehaviorCatalog();
        $behaviors->register((new UiBehaviorMetadataFactory())->fromClass(DropdownBehavior::class));
        return $this->createWithDependencies(UiCatalogProjector::class, compact('primitives', 'components', 'behaviors'));
    }

    #[Test]
    public function catalog_projects_the_registered_contract_and_readable_slot_descriptions(): void
    {
        $projector = $this->projector();
        $card = $projector->envelope(name: 'platform.card')['entries'][0];
        self::assertSame('typed', $card['typing']);
        self::assertSame(['elevated', 'outlined', 'plain'], $card['props_schema']['properties']->variant['enum']);
        self::assertSame('Main content region of the card.', $card['slots']->body['description']);
        self::assertSame('default', $card['examples'][0]['name']);
        self::assertSame($card['contract_version'], $projector->envelope(name: 'platform.card')['entries'][0]['contract_version']);
    }

    #[Test]
    public function detailed_events_and_part_providers_survive_projection(): void
    {
        $field = $this->projector()->envelope(name: 'platform.field')['entries'][0];
        self::assertSame('value', $field['parts']->input['bind']);
        self::assertStringEndsWith('::inputPart', $field['parts']->input['provider']);
        self::assertStringEndsWith('::onInputChanged', $field['events']->{'input.change'}['handler']);

        // transport and response are read off the primitive backing the part,
        // and no primitive declares an event yet — AsUiPrimitive takes an
        // `events` array and all seven builtins pass none. The projection
        // still carries both keys rather than dropping them, so a consumer
        // reads "declared, unresolved" instead of having to infer silence.
        // Asserting a value here would be asserting one this slice does not
        // yet promise; UiOnMetadata says as much in its own docblock.
        $event = (array) $field['events']->{'input.change'};
        self::assertArrayHasKey('transport', $event);
        self::assertNull($event['transport']);
        self::assertArrayHasKey('response', $event);
    }

    #[Test]
    public function behavior_controls_derive_from_the_existing_option_declarations(): void
    {
        $row = $this->projector()->envelope(kind: 'behavior')['entries'][0];
        $option = $row['options'][0];
        $schema = (array) $row['props_schema']['properties'];
        self::assertSame($option['default'], $schema[$option['name']]['default']);
        self::assertSame($option['description'], $schema[$option['name']]['description']);
    }

    #[Test]
    public function unknown_catalog_names_are_an_explicit_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->projector()->envelope(name: 'platform.typo');
    }
}
