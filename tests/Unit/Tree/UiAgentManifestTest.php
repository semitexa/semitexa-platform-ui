<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Tree;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Access\UiPermissions;
use Semitexa\PlatformUi\Application\Service\Tree\NavigateTreeAction;
use Semitexa\PlatformUi\Application\Service\Tree\UiAgentCatalog;
use Semitexa\PlatformUi\Application\Service\Tree\UiTreeActions;
use Semitexa\PlatformUi\Application\Service\Tree\UiAgentManifest;
use Semitexa\PlatformUi\Domain\Model\Component\UiComponentMetadata;
use Semitexa\PlatformUi\Domain\Model\Component\UiSlotMetadata;
use Semitexa\PlatformUi\Domain\Model\Contract\UiCatalogItem;
use Semitexa\PlatformUi\Domain\Model\Contract\UiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;

/**
 * tk-ai-catalog: an agent is told of the components this visitor may use —
 * with their props, slots and an example — and of no other.
 */
final class UiAgentManifestTest extends TestCase
{
    private UiAgentManifest $manifest;

    protected function setUp(): void
    {
        $catalog = new UiAgentCatalog();
        $card = new UiContract('A card', [new UiProp('title', required: true), new UiProp('tone', default: 'neutral', values: ['neutral', 'brand'])], [new UiExample('default', 'Default', ['title' => 'Orders'])], agent: true);
        $secret = new UiContract('Revenue', [new UiProp('title', required: true)], agent: true, permission: 'reports.view');
        $catalog->useEntries([
            'platform.card' => new UiCatalogItem('component', new UiComponentMetadata('Card', 'platform.card', [], ['body' => new UiSlotMetadata('body', null)]), $card, null, '', 1, ''),
            'shop.revenue' => new UiCatalogItem('component', new UiComponentMetadata('Revenue', 'shop.revenue', [], []), $secret, null, '', 1, ''),
        ]);
        $this->manifest = new UiAgentManifest();
        (new \ReflectionProperty($this->manifest, 'catalog'))->setValue($this->manifest, $catalog);
        $actions = new UiTreeActions();
        $actions->useHandlers(['navigate' => new NavigateTreeAction()]);
        (new \ReflectionProperty($this->manifest, 'actions'))->setValue($this->manifest, $actions);
    }

    protected function tearDown(): void
    {
        UiPermissions::reset();
    }

    #[Test]
    public function a_component_the_visitor_may_not_use_is_not_mentioned(): void
    {
        UiPermissions::actAsHolding([]);
        self::assertSame(['platform.card'], array_column($this->manifest->components(), 'name'));
        self::assertStringNotContainsString('shop.revenue', $this->manifest->describe());
        self::assertStringNotContainsString('shop.revenue', (string) json_encode($this->manifest->treeSchema()));

        UiPermissions::actAsHolding(['reports.view']);
        self::assertSame(['platform.card', 'shop.revenue'], array_column($this->manifest->components(), 'name'));
    }

    #[Test]
    public function the_text_and_the_schema_say_what_each_component_takes(): void
    {
        UiPermissions::actAsHolding([]);
        $text = $this->manifest->describe();
        self::assertStringContainsString("platform.card — A card\n  title: string\n  tone?: string one of neutral|brand (default \"neutral\")\n  children into slots: body (default: body)\n  e.g. props {\"title\":\"Orders\"}", $text);

        $node = $this->manifest->treeSchema()['properties']['nodes']['additionalProperties']['oneOf'][0];
        self::assertSame(['const' => 'platform.card'], $node['properties']['type']);
        self::assertSame(['title'], $node['properties']['props']['required']);
        self::assertCount(3, $node['properties']['props']['properties']['title']['anyOf'], 'a value, a $data binding or an $action');
        self::assertFalse($node['additionalProperties']);
    }

    #[Test]
    public function a_command_line_names_permissions_repeated_or_comma_separated(): void
    {
        self::assertSame(['catalog.read', 'catalog.create', 'reports.view'], UiPermissions::grantsOf(['catalog.read, catalog.create', 'reports.view', 'catalog.read', '', 7]));
    }
}
