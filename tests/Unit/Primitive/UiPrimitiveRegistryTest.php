<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Primitive;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Primitive\PrimitiveRenderer;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveRegistry;
use Semitexa\PlatformUi\Attribute\AsUiPrimitive;
use Semitexa\PlatformUi\Domain\Exception\PrimitiveRegistryException;
use Semitexa\PlatformUi\Domain\Model\Primitive\UiEventResponseMode;
use Semitexa\PlatformUi\Domain\Model\Primitive\UiEventTransport;
use Semitexa\PlatformUi\Domain\Model\Primitive\UiPrimitiveEvent;

final class UiPrimitiveRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        UiPrimitiveRegistry::reset();
    }

    #[Test]
    public function extracts_metadata_from_attribute_with_explicit_ui_alias(): void
    {
        $factory = new UiPrimitiveMetadataFactory();
        $metadata = $factory->fromClass(AttrExplicitUi::class);

        self::assertSame('platform.button', $metadata->name);
        self::assertSame('button', $metadata->ui);
        self::assertSame(AttrExplicitUi::class, $metadata->class);
        self::assertSame('@platform-ui/primitives/button.twig', $metadata->template);
        self::assertCount(1, $metadata->events);
        self::assertSame('click', $metadata->events[0]->name);
        self::assertSame(UiEventTransport::Http, $metadata->events[0]->transport);
    }

    #[Test]
    public function derives_ui_alias_from_last_name_segment_when_omitted(): void
    {
        $factory = new UiPrimitiveMetadataFactory();
        $metadata = $factory->fromClass(AttrDerivedUi::class);

        self::assertSame('platform.field-shell', $metadata->name);
        self::assertSame('field-shell', $metadata->ui);
    }

    #[Test]
    public function rejects_empty_name(): void
    {
        $factory = new UiPrimitiveMetadataFactory();
        $this->expectException(PrimitiveRegistryException::class);
        $factory->fromAttribute(self::class, new AsUiPrimitive(name: '   '));
    }

    #[Test]
    public function rejects_invalid_ui_alias(): void
    {
        $factory = new UiPrimitiveMetadataFactory();
        $this->expectException(PrimitiveRegistryException::class);
        $factory->fromAttribute(self::class, new AsUiPrimitive(name: 'platform.x', ui: '???'));
    }

    #[Test]
    public function rejects_duplicate_event_declarations(): void
    {
        $factory = new UiPrimitiveMetadataFactory();
        $this->expectException(PrimitiveRegistryException::class);
        $factory->fromAttribute(self::class, new AsUiPrimitive(
            name: 'platform.x',
            events: [
                new UiPrimitiveEvent(name: 'click'),
                new UiPrimitiveEvent(name: 'click'),
            ],
        ));
    }

    #[Test]
    public function registry_resolves_by_name_and_by_ui_alias(): void
    {
        $factory = new UiPrimitiveMetadataFactory();
        UiPrimitiveRegistry::register($factory->fromClass(AttrExplicitUi::class));

        self::assertNotNull(UiPrimitiveRegistry::getByName('platform.button'));
        self::assertNotNull(UiPrimitiveRegistry::getByUi('button'));

        $byName = UiPrimitiveRegistry::get('platform.button');
        $byAlias = UiPrimitiveRegistry::get('button');
        self::assertNotNull($byName);
        self::assertNotNull($byAlias);
        self::assertSame($byName->class, $byAlias->class);
    }

    #[Test]
    public function registry_rejects_duplicate_canonical_name(): void
    {
        $factory = new UiPrimitiveMetadataFactory();
        $a = $factory->fromClass(AttrExplicitUi::class);
        $b = $factory->fromClass(AttrDuplicateName::class);

        UiPrimitiveRegistry::register($a);
        $this->expectException(PrimitiveRegistryException::class);
        UiPrimitiveRegistry::register($b);
    }

    #[Test]
    public function registry_rejects_duplicate_ui_alias(): void
    {
        $factory = new UiPrimitiveMetadataFactory();
        UiPrimitiveRegistry::register($factory->fromClass(AttrExplicitUi::class));
        $colliding = $factory->fromClass(AttrCollidingAlias::class);

        $this->expectException(PrimitiveRegistryException::class);
        UiPrimitiveRegistry::register($colliding);
    }

    #[Test]
    public function renderer_resolves_through_registry(): void
    {
        $factory = new UiPrimitiveMetadataFactory();
        UiPrimitiveRegistry::register($factory->fromClass(AttrExplicitUi::class));

        $renderer = new PrimitiveRenderer();
        $resolved = $renderer->resolve('button', ['text' => 'Save']);

        self::assertSame('platform.button', $resolved['primitive']->name);
        self::assertSame('button', $resolved['rootAttributes']['ui']);
        self::assertSame('platform.button', $resolved['rootAttributes']['data-ui-primitive']);
    }

    #[Test]
    public function renderer_fallback_emits_stable_root_markers(): void
    {
        $factory = new UiPrimitiveMetadataFactory();
        UiPrimitiveRegistry::register($factory->fromClass(AttrNoTemplate::class));

        $renderer = new PrimitiveRenderer();
        $html = $renderer->render('badge', ['text' => 'Active']);

        self::assertStringContainsString('data-ui-primitive="platform.badge"', $html);
        self::assertStringContainsString('ui="badge"', $html);
        self::assertStringContainsString('Active', $html);
    }

    #[Test]
    public function renderer_collects_declared_style_asset(): void
    {
        \Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore::reset();
        $factory = new UiPrimitiveMetadataFactory();
        UiPrimitiveRegistry::register($factory->fromClass(AttrWithAsset::class));

        $renderer = new PrimitiveRenderer();
        $renderer->render('asset-badge');

        $collector = \Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore::get();
        self::assertTrue($collector->has('platform-ui:css:full'));
        \Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore::reset();
    }

    #[Test]
    public function renderer_does_not_register_assets_when_none_declared(): void
    {
        \Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore::reset();
        $factory = new UiPrimitiveMetadataFactory();
        UiPrimitiveRegistry::register($factory->fromClass(AttrNoTemplate::class));

        $renderer = new PrimitiveRenderer();
        $renderer->render('badge', ['text' => 'Active']);

        $collector = \Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore::get();
        self::assertFalse($collector->has('platform-ui:css:full'));
        \Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore::reset();
    }

    #[Test]
    public function renderer_deduplicates_asset_keys(): void
    {
        \Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore::reset();
        $factory = new UiPrimitiveMetadataFactory();
        UiPrimitiveRegistry::register($factory->fromClass(AttrWithAsset::class));

        $renderer = new PrimitiveRenderer();
        $renderer->render('asset-badge');
        $renderer->render('asset-badge');

        $collector = \Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore::get();
        self::assertTrue($collector->has('platform-ui:css:full'));
        \Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore::reset();
    }

    #[Test]
    public function renderer_fails_on_unknown_primitive(): void
    {
        $renderer = new PrimitiveRenderer();
        $this->expectException(PrimitiveRegistryException::class);
        $renderer->resolve('unknown-primitive');
    }

    #[Test]
    public function builtin_primitive_classes_are_registerable(): void
    {
        $factory = new UiPrimitiveMetadataFactory();

        UiPrimitiveRegistry::register($factory->fromClass(
            \Semitexa\PlatformUi\Application\Service\Primitive\Builtin\ButtonPrimitive::class,
        ));
        UiPrimitiveRegistry::register($factory->fromClass(
            \Semitexa\PlatformUi\Application\Service\Primitive\Builtin\InputPrimitive::class,
        ));
        UiPrimitiveRegistry::register($factory->fromClass(
            \Semitexa\PlatformUi\Application\Service\Primitive\Builtin\BadgePrimitive::class,
        ));

        self::assertNotNull(UiPrimitiveRegistry::getByName('platform.button'));
        self::assertNotNull(UiPrimitiveRegistry::getByUi('input'));
        $badge = UiPrimitiveRegistry::getByName('platform.badge');
        self::assertNotNull($badge);
        self::assertSame('@platform-ui/primitives/runtime/badge.html.twig', $badge->template);
        self::assertSame('platform-ui:css:full', $badge->style);
    }

    #[Test]
    public function event_declaration_carries_native_and_response_metadata(): void
    {
        $factory = new UiPrimitiveMetadataFactory();
        $metadata = $factory->fromClass(AttrWithEvent::class);

        $click = $metadata->event('click');
        self::assertNotNull($click);
        self::assertSame('mousedown', $click->nativeName());
        self::assertSame(UiEventResponseMode::Command, $click->response);
        self::assertTrue($metadata->declaresEvent('click'));
        self::assertFalse($metadata->declaresEvent('change'));
    }
}

#[AsUiPrimitive(
    name: 'platform.button',
    ui: 'button',
    template: '@platform-ui/primitives/button.twig',
    events: [new UiPrimitiveEvent(name: 'click')],
)]
final class AttrExplicitUi {}

#[AsUiPrimitive(name: 'platform.field-shell')]
final class AttrDerivedUi {}

#[AsUiPrimitive(name: 'platform.button', ui: 'btn2')]
final class AttrDuplicateName {}

#[AsUiPrimitive(name: 'platform.alt-button', ui: 'button')]
final class AttrCollidingAlias {}

#[AsUiPrimitive(
    name: 'platform.cta',
    ui: 'cta',
    events: [
        new UiPrimitiveEvent(
            name: 'click',
            native: 'mousedown',
            response: UiEventResponseMode::Command,
        ),
    ],
)]
final class AttrWithEvent {}

#[AsUiPrimitive(name: 'platform.badge', ui: 'badge')]
final class AttrNoTemplate {}

#[AsUiPrimitive(
    name: 'platform.asset-badge',
    ui: 'asset-badge',
    style: 'platform-ui:css:full',
)]
final class AttrWithAsset {}
