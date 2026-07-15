<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Behavior;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Behavior\UiBehaviorMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Behavior\UiBehaviorRegistry;
use Semitexa\PlatformUi\Attribute\AsUiBehavior;
use Semitexa\PlatformUi\Domain\Exception\BehaviorRegistryException;
use Semitexa\PlatformUi\Domain\Model\Behavior\UiBehaviorOption;
use Semitexa\PlatformUi\Domain\Model\Behavior\UiOptionType;

final class UiBehaviorRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        UiBehaviorRegistry::reset();
    }

    #[Test]
    public function extracts_metadata_with_explicit_ui_and_typed_options(): void
    {
        $factory = new UiBehaviorMetadataFactory();
        $metadata = $factory->fromClass(BehDropdown::class);

        self::assertSame('platform.dropdown', $metadata->name);
        self::assertSame('dropdown', $metadata->ui);
        self::assertSame(BehDropdown::class, $metadata->class);
        self::assertSame('platform-ui:js:dropdown', $metadata->script);
        self::assertCount(2, $metadata->options);
        self::assertSame('mode', $metadata->options[0]->name);
        self::assertSame(UiOptionType::Enum, $metadata->options[0]->type);
        self::assertSame('click', $metadata->options[0]->default);
        self::assertTrue($metadata->declaresA11y('focus-trap'));
        self::assertFalse($metadata->declaresA11y('nope'));
        self::assertSame(['platform.menu'], $metadata->requires);
    }

    #[Test]
    public function derives_ui_alias_from_last_name_segment_when_omitted(): void
    {
        $factory = new UiBehaviorMetadataFactory();
        $metadata = $factory->fromClass(BehDerivedUi::class);

        self::assertSame('platform.scroll-spy', $metadata->name);
        self::assertSame('scroll-spy', $metadata->ui);
    }

    #[Test]
    public function rejects_empty_name(): void
    {
        $factory = new UiBehaviorMetadataFactory();
        $this->expectException(BehaviorRegistryException::class);
        $factory->fromAttribute(self::class, new AsUiBehavior(name: '   '));
    }

    #[Test]
    public function rejects_invalid_ui_alias(): void
    {
        $factory = new UiBehaviorMetadataFactory();
        $this->expectException(BehaviorRegistryException::class);
        $factory->fromAttribute(self::class, new AsUiBehavior(name: 'platform.x', ui: '???'));
    }

    #[Test]
    public function rejects_duplicate_option_names(): void
    {
        $factory = new UiBehaviorMetadataFactory();
        $this->expectException(BehaviorRegistryException::class);
        $factory->fromAttribute(self::class, new AsUiBehavior(
            name: 'platform.x',
            options: [
                new UiBehaviorOption('mode'),
                new UiBehaviorOption('mode'),
            ],
        ));
    }

    #[Test]
    public function rejects_enum_option_without_values(): void
    {
        $factory = new UiBehaviorMetadataFactory();
        $this->expectException(BehaviorRegistryException::class);
        $factory->fromAttribute(self::class, new AsUiBehavior(
            name: 'platform.x',
            options: [new UiBehaviorOption('mode', UiOptionType::Enum)],
        ));
    }

    #[Test]
    public function stores_the_trimmed_option_name_so_the_client_dsl_matches(): void
    {
        $factory = new UiBehaviorMetadataFactory();
        $meta = $factory->fromAttribute(self::class, new AsUiBehavior(
            name: 'platform.x',
            options: [new UiBehaviorOption('  mode  ')],
        ));

        // The stored option — and the descriptor shipped to the client — must
        // carry the validated (trimmed) name, not the padded original.
        self::assertSame('mode', $meta->options[0]->name);
        self::assertSame('mode', $meta->options[0]->toDescriptor()['name']);
    }

    #[Test]
    public function registry_resolves_by_name_and_by_ui_alias(): void
    {
        $factory = new UiBehaviorMetadataFactory();
        UiBehaviorRegistry::register($factory->fromClass(BehDropdown::class));

        self::assertNotNull(UiBehaviorRegistry::getByName('platform.dropdown'));
        self::assertNotNull(UiBehaviorRegistry::getByUi('dropdown'));

        $byName = UiBehaviorRegistry::get('platform.dropdown');
        $byAlias = UiBehaviorRegistry::get('dropdown');
        self::assertNotNull($byName);
        self::assertNotNull($byAlias);
        self::assertSame($byName->class, $byAlias->class);
        self::assertTrue(UiBehaviorRegistry::has('dropdown'));
    }

    #[Test]
    public function registry_rejects_duplicate_canonical_name(): void
    {
        $factory = new UiBehaviorMetadataFactory();
        UiBehaviorRegistry::register($factory->fromClass(BehDropdown::class));

        $this->expectException(BehaviorRegistryException::class);
        UiBehaviorRegistry::register($factory->fromClass(BehDuplicateName::class));
    }

    #[Test]
    public function registry_rejects_duplicate_ui_alias(): void
    {
        $factory = new UiBehaviorMetadataFactory();
        UiBehaviorRegistry::register($factory->fromClass(BehDropdown::class));

        $this->expectException(BehaviorRegistryException::class);
        UiBehaviorRegistry::register($factory->fromClass(BehCollidingAlias::class));
    }

    #[Test]
    public function client_descriptor_carries_option_coercion_table(): void
    {
        $factory = new UiBehaviorMetadataFactory();
        $descriptor = $factory->fromClass(BehDropdown::class)->toDescriptor();

        self::assertSame('dropdown', $descriptor['ui']);
        self::assertSame('mode', $descriptor['options'][0]['name']);
        self::assertSame('enum', $descriptor['options'][0]['type']);
        self::assertSame(['click', 'hover'], $descriptor['options'][0]['values']);
        self::assertSame('number', $descriptor['options'][1]['type']);
        self::assertSame(4, $descriptor['options'][1]['default']);
    }
}

#[AsUiBehavior(
    name: 'platform.dropdown',
    ui: 'dropdown',
    script: 'platform-ui:js:dropdown',
    options: [
        new UiBehaviorOption('mode', UiOptionType::Enum, default: 'click', values: ['click', 'hover']),
        new UiBehaviorOption('offset', UiOptionType::Number, default: 4),
    ],
    a11y: ['aria-expanded', 'focus-trap', 'esc-dismiss'],
    requires: ['platform.menu'],
)]
final class BehDropdown {}

#[AsUiBehavior(name: 'platform.scroll-spy')]
final class BehDerivedUi {}

#[AsUiBehavior(name: 'platform.dropdown', ui: 'dropdown2')]
final class BehDuplicateName {}

#[AsUiBehavior(name: 'platform.alt-dropdown', ui: 'dropdown')]
final class BehCollidingAlias {}
