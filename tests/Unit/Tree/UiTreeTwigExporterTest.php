<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Tree;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Tree\NavigateTreeAction;
use Semitexa\PlatformUi\Application\Service\Tree\UiAgentCatalog;
use Semitexa\PlatformUi\Application\Service\Tree\UiTreeActions;
use Semitexa\PlatformUi\Application\Service\Tree\UiTreeParser;
use Semitexa\PlatformUi\Application\Service\Tree\UiTreeTwigExporter;
use Semitexa\PlatformUi\Domain\Model\Component\UiComponentMetadata;
use Semitexa\PlatformUi\Domain\Model\Component\UiSlotMetadata;
use Semitexa\PlatformUi\Domain\Model\Contract\UiCatalogItem;
use Semitexa\PlatformUi\Domain\Model\Contract\UiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;
use Semitexa\PlatformUi\Domain\Model\Primitive\PrimitiveMetadata;
use Semitexa\PlatformUi\Domain\Model\Tree\UiTree;

/**
 * The exported Twig is the tree as hand-written component calls. Its HTML is
 * the renderer's (checked against the real runtime byte for byte); here the
 * text itself is pinned, because the same tree must always give the same file.
 */
final class UiTreeTwigExporterTest extends TestCase
{
    private UiTreeTwigExporter $exporter;

    protected function setUp(): void
    {
        $catalog = new UiAgentCatalog();
        $catalog->useEntries([
            'platform.card' => new UiCatalogItem('component', new UiComponentMetadata('Card', 'platform.card', [], ['body' => new UiSlotMetadata('body', null), 'header' => new UiSlotMetadata('header', null)]), new UiContract('Card', [new UiProp('title', required: true)], agent: true), null, '', 1, ''),
            'platform.stat' => new UiCatalogItem('component', new UiComponentMetadata('Stat', 'platform.stat', [], []), new UiContract('Stat', [new UiProp('label'), new UiProp('value')], agent: true), null, '', 1, ''),
            'platform.badge' => new UiCatalogItem('primitive', new PrimitiveMetadata('Badge', 'platform.badge', 'badge', null, null, null, []), new UiContract('Badge', [new UiProp('text'), new UiProp('dot', UiPropType::Boolean)], agent: true), null, '', 1, ''),
        ]);
        $actions = new UiTreeActions();
        $actions->useHandlers(['navigate' => new NavigateTreeAction()]);
        $this->exporter = new UiTreeTwigExporter();
        (new \ReflectionProperty($this->exporter, 'catalog'))->setValue($this->exporter, $catalog);
        (new \ReflectionProperty($this->exporter, 'actions'))->setValue($this->exporter, $actions);
    }

    #[Test]
    public function children_go_into_their_slot_and_bindings_become_the_values_they_resolve_to(): void
    {
        $twig = $this->exporter->write($this->tree([
            'version' => UiTree::VERSION,
            'root' => 'page',
            'nodes' => [
                'page' => ['type' => 'platform.card', 'props' => ['title' => "Orders 'today'"], 'children' => ['open', 'closed', 'live']],
                'open' => ['type' => 'platform.stat', 'props' => ['label' => 'Open', 'value' => ['$data' => '/open']]],
                'closed' => ['type' => 'platform.stat', 'props' => ['label' => 'Closed', 'value' => '3']],
                'live' => ['type' => 'platform.badge', 'props' => ['text' => 'Live', 'dot' => true], 'slot' => 'header'],
            ],
            'data' => ['open' => '12'],
        ]));

        self::assertSame(<<<'TWIG'
            <div data-ui-tree>{{ component('platform.card', { title: 'Orders \'today\'' }, {
                body: component('platform.stat', { label: 'Open', value: '12' })
                    ~ component('platform.stat', { label: 'Closed', value: '3' }),
                header: primitive('platform.badge', { text: 'Live', dot: true }),
            }) }}</div>

            TWIG, $twig);
    }

    #[Test]
    public function the_same_tree_always_gives_the_same_text(): void
    {
        $document = [
            'version' => UiTree::VERSION,
            'root' => 'page',
            'nodes' => ['page' => ['type' => 'platform.card', 'props' => ['title' => 'A']]],
        ];

        self::assertSame($this->exporter->write($this->tree($document)), $this->exporter->write($this->tree($document)));
        self::assertSame("<div data-ui-tree>{{ component('platform.card', { title: 'A' }) }}</div>\n", $this->exporter->write($this->tree($document)));
    }

    /** @param array<string, mixed> $document */
    private function tree(array $document): UiTree
    {
        $parsed = (new UiTreeParser())->parse($document);
        self::assertNotNull($parsed['tree'], json_encode($parsed['errors']) ?: '');

        return $parsed['tree'];
    }
}
