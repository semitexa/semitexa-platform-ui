<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Tree;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Tree\UiTreeParser;
use Semitexa\PlatformUi\Domain\Model\Tree\UiTree;

/**
 * tk-ai-tree: a UI tree is read as a flat, id-referenced document, and every
 * fault of its shape is reported at once — with where it is and how to fix it.
 */
final class UiTreeParserTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function valid(): array
    {
        return [
            'version' => UiTree::VERSION,
            'root' => 'page',
            'nodes' => [
                'page' => ['type' => 'platform.card', 'props' => ['title' => 'Orders'], 'children' => ['open', 'late']],
                'open' => ['type' => 'platform.stat', 'props' => ['label' => 'Open', 'value' => ['$data' => '/orders/open']]],
                'late' => ['type' => 'platform.stat', 'props' => ['label' => 'Late', 'value' => ['$data' => '/orders/late']], 'slot' => 'footer'],
            ],
            'data' => ['orders' => ['open' => 12, 'late' => 0]],
            'actions' => ['newOrder' => ['kind' => 'navigate', 'to' => '/orders?create']],
        ];
    }

    /** @return list<string> */
    private static function codes(array $result): array
    {
        return array_map(static fn ($e): string => $e->code, $result['errors']);
    }

    #[Test]
    public function a_sound_tree_reads_as_nodes_data_and_actions(): void
    {
        $result = (new UiTreeParser())->parse(json_encode(self::valid()));

        self::assertSame([], self::codes($result));
        $tree = $result['tree'];
        self::assertSame(['open', 'late'], $tree?->node('page')?->children);
        self::assertSame('footer', $tree?->node('late')?->slot);
        self::assertSame('/orders/open', UiTree::bindingOf($tree?->node('open')?->props['value']));
        self::assertSame(['found' => true, 'value' => 12], $tree?->resolve('/orders/open'));
        self::assertSame('navigate', $tree?->actions['newOrder']['kind']);
    }

    #[Test]
    public function every_fault_of_the_shape_is_reported_at_once_with_its_path(): void
    {
        $doc = self::valid();
        $doc['version'] = 'a2ui/0.9';
        $doc['extra'] = true;
        $doc['nodes']['page']['children'][] = 'ghost';
        $doc['nodes']['open']['type'] = 'Card';
        $doc['nodes']['late']['props']['value'] = ['$data' => '/orders/missing'];
        $doc['nodes']['stray'] = ['type' => 'platform.badge'];
        $doc['actions']['launch'] = ['kind' => 'Shell Script'];

        $result = (new UiTreeParser())->parse($doc);

        self::assertNull($result['tree']);
        self::assertSame(['tree.unknown_field', 'tree.version', 'tree.node_type', 'tree.child_unknown', 'tree.unreachable', 'tree.action_kind'], self::codes($result));
        $byCode = [];
        foreach ($result['errors'] as $error) {
            $byCode[$error->code] = $error;
        }
        self::assertSame('/nodes/page/children/2', $byCode['tree.child_unknown']->path);
        self::assertSame('ghost', $byCode['tree.child_unknown']->got);
        self::assertSame('/nodes/open/type', $byCode['tree.node_type']->path);
        self::assertSame('{"kind": "navigate", …}', $byCode['tree.action_kind']->expected, 'which kinds exist is the validator\'s to say');
        self::assertArrayHasKey('hint', $byCode['tree.unreachable']->toArray());
    }

    #[Test]
    public function a_binding_to_nothing_is_reported_once_the_shape_is_sound(): void
    {
        $doc = self::valid();
        $doc['nodes']['late']['props']['value'] = ['$data' => '/orders/missing'];
        $doc['nodes']['open']['props']['value'] = ['$data' => 'orders/open', 'extra' => 1];

        $result = (new UiTreeParser())->parse($doc);

        self::assertSame(['tree.data_pointer', 'tree.data_missing'], self::codes($result));
        self::assertSame('/nodes/late/props/value', $result['errors'][1]->path);
    }

    #[Test]
    public function a_node_has_one_parent_and_a_tree_has_no_loop(): void
    {
        $doc = self::valid();
        $doc['nodes']['open']['children'] = ['late'];      // late now has two parents
        $doc['nodes']['a'] = ['type' => 'platform.card', 'children' => ['b']];
        $doc['nodes']['b'] = ['type' => 'platform.card', 'children' => ['a']]; // a loop hanging from nothing

        $codes = self::codes((new UiTreeParser())->parse($doc));

        self::assertContains('tree.child_twice', $codes);
        self::assertSame(2, count(array_keys($codes, 'tree.unreachable', true)), 'a detached loop is reported, not walked forever');
    }

    #[Test]
    public function what_is_not_a_tree_at_all_says_so(): void
    {
        $parser = new UiTreeParser();
        self::assertSame(['tree.json'], self::codes($parser->parse('{nope')));
        self::assertSame(['tree.shape'], self::codes($parser->parse([1, 2])));
        self::assertSame(['tree.version', 'tree.nodes'], self::codes($parser->parse(['root' => 'x'])));
        $huge = self::valid();
        $huge['nodes'] = array_fill_keys(array_map(static fn (int $i): string => 'n' . $i, range(0, UiTreeParser::MAX_NODES)), ['type' => 'platform.card']);
        self::assertSame(['tree.too_large'], self::codes($parser->parse($huge)));
    }
}
