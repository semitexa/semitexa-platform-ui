<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Tree;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Authorization\Domain\Contract\AuthorizerInterface;
use Semitexa\Authorization\Domain\Enum\DenyReason;
use Semitexa\Authorization\Domain\Model\AccessDecision;
use Semitexa\Authorization\Domain\Model\AccessPolicy;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Auth\AuthenticatableInterface;
use Semitexa\Core\Authorization\SubjectInterface;
use Semitexa\PlatformUi\Application\Component\Builtin\ListComponent;
use Semitexa\PlatformUi\Application\Service\Access\UiPermissions;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\ButtonPrimitive;
use Semitexa\PlatformUi\Application\Service\Tree\NavigateTreeAction;
use Semitexa\PlatformUi\Application\Service\Tree\UiAgentCatalog;
use Semitexa\PlatformUi\Application\Service\Tree\UiTreeActions;
use Semitexa\PlatformUi\Application\Service\Tree\UiTreeParser;
use Semitexa\PlatformUi\Application\Service\Tree\UiTreeValidator;
use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Component\UiComponentMetadata;
use Semitexa\PlatformUi\Domain\Model\Component\UiSlotMetadata;
use Semitexa\PlatformUi\Domain\Model\Contract\UiCatalogItem;
use Semitexa\PlatformUi\Domain\Model\Contract\UiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;
use Semitexa\PlatformUi\Domain\Model\Primitive\PrimitiveMetadata;
use Semitexa\PlatformUi\Domain\Model\Tree\UiTree;

/**
 * tk-ai-validate: a tree is checked against the same catalog and the same
 * permissions a person composing the screen gets, and every fault comes back
 * with where it is and how to fix it.
 */
final class UiTreeValidatorTest extends TestCase
{
    private UiTreeValidator $validator;

    protected function setUp(): void
    {
        $catalog = new UiAgentCatalog();
        $card = new UiContract('Card', [new UiProp('title', required: true), new UiProp('variant', default: 'plain', values: ['plain', 'elevated'])], agent: true);
        $stat = new UiContract('Stat', [new UiProp('label', required: true), new UiProp('value', required: true)], agent: true);
        $revenue = new UiContract('Revenue chart', [new UiProp('title', required: true)], agent: true, permission: 'reports.view');
        $badge = new UiContract('Badge', [new UiProp('text', required: true), new UiProp('dot', UiPropType::Boolean, default: false)], agent: true);
        $catalog->useEntries([
            'platform.card' => new UiCatalogItem('component', new UiComponentMetadata('Card', 'platform.card', [], ['header' => new UiSlotMetadata('header', null), 'body' => new UiSlotMetadata('body', null)]), $card, null, '', 1, ''),
            'platform.stat' => new UiCatalogItem('component', new UiComponentMetadata('Stat', 'platform.stat', [], []), $stat, null, '', 1, ''),
            'shop.revenue' => new UiCatalogItem('component', new UiComponentMetadata('Revenue', 'shop.revenue', [], []), $revenue, null, '', 1, ''),
            'platform.badge' => new UiCatalogItem('primitive', new PrimitiveMetadata('Badge', 'platform.badge', 'badge', null, null, null, []), $badge, null, '', 1, ''),
        ]);
        $this->validator = new UiTreeValidator();
        (new \ReflectionProperty($this->validator, 'catalog'))->setValue($this->validator, $catalog);
        $actions = new UiTreeActions();
        $actions->useHandlers(['navigate' => new NavigateTreeAction()]);
        (new \ReflectionProperty($this->validator, 'actions'))->setValue($this->validator, $actions);
        (new \ReflectionProperty($this->validator, 'parser'))->setValue($this->validator, new UiTreeParser());
        $this->grant([]);
    }

    protected function tearDown(): void
    {
        UiPermissions::reset();
    }

    /** @return array<string, mixed> */
    private static function tree(): array
    {
        return [
            'version' => UiTree::VERSION,
            'root' => 'page',
            'nodes' => [
                'page' => ['type' => 'platform.card', 'props' => ['title' => 'Orders'], 'children' => ['open', 'status']],
                'open' => ['type' => 'platform.stat', 'props' => ['label' => 'Open', 'value' => ['$data' => '/open']]],
                'status' => ['type' => 'platform.badge', 'props' => ['text' => 'Live'], 'slot' => 'header'],
            ],
            'data' => ['open' => '12'],
        ];
    }

    /** @return list<string> */
    private static function codes(array $result): array
    {
        return array_map(static fn ($e): string => $e->code, $result['errors']);
    }

    #[Test]
    public function a_tree_of_open_components_with_their_props_passes(): void
    {
        $result = $this->validator->check(self::tree());

        self::assertSame([], self::codes($result));
        self::assertInstanceOf(UiTree::class, $result['tree']);
    }

    #[Test]
    public function every_contract_fault_is_reported_with_what_was_expected(): void
    {
        $doc = self::tree();
        $doc['nodes']['page']['props'] = ['titel' => 'Orders', 'variant' => 'glossy'];
        $doc['nodes']['open']['type'] = 'platform.sta';
        $doc['nodes']['status']['props']['dot'] = 'yes';
        $doc['nodes']['status']['slot'] = 'aside';
        $doc['nodes']['status']['children'] = [];

        $result = $this->validator->check($doc);
        $byCode = [];
        foreach ($result['errors'] as $error) {
            $byCode[$error->code][] = $error;
        }

        self::assertNull($result['tree']);
        self::assertSame(['tree.prop_unknown', 'tree.prop_invalid', 'tree.prop_required', 'tree.slot_unknown', 'tree.component_unknown', 'tree.prop_invalid'], self::codes($result));
        self::assertSame('Did you mean "title"?', $byCode['tree.prop_unknown'][0]->hint);
        self::assertSame('Did you mean "platform.stat"?', $byCode['tree.component_unknown'][0]->hint);
        self::assertStringContainsString('"enum":["plain","elevated"]', (string) $byCode['tree.prop_invalid'][0]->expected);
        self::assertSame('/nodes/status/slot', $byCode['tree.slot_unknown'][0]->path);
        self::assertSame('body, header', $byCode['tree.slot_unknown'][0]->expected, 'the default slot first');
        self::assertSame('/nodes/status/props/dot', $byCode['tree.prop_invalid'][1]->path);
    }

    #[Test]
    public function a_component_behind_a_permission_is_refused_to_a_visitor_without_it(): void
    {
        $doc = self::tree();
        $doc['nodes']['chart'] = ['type' => 'shop.revenue', 'props' => ['title' => 'Revenue']];
        $doc['nodes']['page']['children'][] = 'chart';

        self::assertSame(['tree.component_forbidden'], self::codes($this->validator->check($doc)));

        $this->grant(['reports.view']);
        self::assertSame([], self::codes($this->validator->check($doc)), 'the same tree, for who may see it');
    }

    #[Test]
    public function data_is_checked_by_the_value_it_binds_to_and_actions_by_name(): void
    {
        $doc = self::tree();
        $doc['data'] = ['open' => 12];
        $doc['nodes']['status']['props']['text'] = ['$action' => 'refresh'];
        $doc['nodes']['open']['children'] = ['nested'];
        $doc['nodes']['nested'] = ['type' => 'platform.badge', 'props' => ['text' => 'x']];

        $result = $this->validator->check($doc);

        self::assertSame(['tree.prop_invalid', 'tree.children_not_allowed', 'tree.action_unknown'], self::codes($result));
        self::assertStringContainsString('The data at "/open" does not fit', $result['errors'][0]->message);
    }

    #[Test]
    public function an_action_is_a_kind_the_application_runs_with_arguments_that_fit(): void
    {
        $doc = self::tree();
        $doc['actions'] = [
            'home' => ['kind' => 'navigate', 'to' => '/orders'],
            'away' => ['kind' => 'navigate', 'to' => 'https://evil.example/'],
            'quote' => ['kind' => 'navigate', 'to' => '/x"onmouseover="alert(1)'],
            'shell' => ['kind' => 'exec', 'cmd' => 'rm -rf /'],
        ];

        $result = $this->validator->check($doc);

        self::assertSame(['tree.action_target', 'tree.action_target', 'tree.action_kind'], self::codes($result));
        self::assertSame('/actions/away/to', $result['errors'][0]->path);
        self::assertSame('navigate', $result['errors'][2]->expected, 'only the kinds registered');
    }

    #[Test]
    public function an_action_reference_must_fit_the_prop_it_fills(): void
    {
        // The renderer draws {"$action": "go"} as the action's path, "/orders":
        // fine for a string prop, never for an enum or a boolean.
        $doc = self::tree();
        $doc['actions'] = ['go' => ['kind' => 'navigate', 'to' => '/orders']];
        $doc['nodes']['page']['props']['variant'] = ['$action' => 'go'];
        $doc['nodes']['status']['props']['dot'] = ['$action' => 'go'];

        $result = $this->validator->check($doc);

        self::assertNull($result['tree']);
        self::assertSame(['tree.prop_invalid', 'tree.prop_invalid'], self::codes($result));
        self::assertSame('/nodes/page/props/variant', $result['errors'][0]->path);
        self::assertStringContainsString('The action "go" does not fit', $result['errors'][0]->message);
        self::assertSame('/nodes/status/props/dot', $result['errors'][1]->path);

        $doc = self::tree();
        $doc['actions'] = ['go' => ['kind' => 'navigate', 'to' => '/orders']];
        $doc['nodes']['status']['props']['text'] = ['$action' => 'go'];
        self::assertSame([], self::codes($this->validator->check($doc)), 'a string prop takes the path');
    }

    /**
     * Every shape of a fault an action reports about itself: under a field of
     * it (/actions/go/to, /actions/go/kind) and at the action itself
     * (/actions/go, an extra field).
     *
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function brokenActions(): iterable
    {
        yield 'target' => [['kind' => 'navigate', 'to' => 'orders'], 'tree.action_target'];
        yield 'kind' => [['kind' => 'exec', 'to' => '/orders'], 'tree.action_kind'];
        yield 'extra field' => [['kind' => 'navigate', 'to' => '/orders', 'then' => 'x'], 'tree.action_field'];
    }

    /** @param array<string, mixed> $action */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('brokenActions')]
    public function a_broken_action_is_reported_once_not_again_at_each_reference(array $action, string $code): void
    {
        // The prop that refers to a broken action would only repeat its fault,
        // so a model sees one fault, not two.
        $doc = self::tree();
        $doc['actions'] = ['go' => $action];
        $doc['nodes']['page']['props']['variant'] = ['$action' => 'go'];

        self::assertSame([$code], self::codes($this->validator->check($doc)));
    }

    #[Test]
    public function a_sound_action_whose_name_starts_a_broken_ones_is_still_checked(): void
    {
        // The broken action's faults sit under /actions/gone/..., which begins
        // with /actions/go: only /actions/go itself or /actions/go/... may
        // mark "go" broken, so the reference to the sound "go" is still
        // checked and its path does not fit an enum.
        $doc = self::tree();
        $doc['actions'] = ['go' => ['kind' => 'navigate', 'to' => '/orders'], 'gone' => ['kind' => 'navigate', 'to' => 'orders']];
        $doc['nodes']['page']['props']['variant'] = ['$action' => 'go'];

        self::assertSame(['tree.prop_invalid', 'tree.action_target'], self::codes($this->validator->check($doc)));
    }

    /**
     * A link prop an agent can fill (a button's href, a list item's href) is a
     * path on this site, however it is written: literally, bound with $data,
     * or nested in a list's items. The contracts are the real ones.
     */
    #[Test]
    public function a_link_prop_takes_only_a_path_on_this_site(): void
    {
        // The declared props, opened to agents here whatever the class says.
        $contract = static function (string $class): UiContract {
            $declared = (new \ReflectionClass($class))->getAttributes(AsUiContract::class)[0]->newInstance()->metadata();

            return new UiContract($declared->summary, array_values($declared->props), agent: true);
        };
        $catalog = new UiAgentCatalog();
        $catalog->useEntries([
            'platform.card' => new UiCatalogItem('component', new UiComponentMetadata('Card', 'platform.card', [], ['body' => new UiSlotMetadata('body', null)]), new UiContract('Card', [new UiProp('title', required: true)], agent: true), null, '', 1, ''),
            'platform.button' => new UiCatalogItem('primitive', new PrimitiveMetadata('Button', 'platform.button', 'button', null, null, null, []), $contract(ButtonPrimitive::class), null, '', 1, ''),
            'platform.list' => new UiCatalogItem('component', new UiComponentMetadata('List', 'platform.list', [], []), $contract(ListComponent::class), null, '', 1, ''),
        ]);
        (new \ReflectionProperty($this->validator, 'catalog'))->setValue($this->validator, $catalog);
        $doc = [
            'version' => UiTree::VERSION,
            'root' => 'page',
            'nodes' => [
                'page' => ['type' => 'platform.card', 'props' => ['title' => 'Go'], 'children' => ['script', 'away', 'bound', 'list', 'home']],
                'script' => ['type' => 'platform.button', 'props' => ['text' => 'Go', 'href' => 'javascript:alert(document.cookie)']],
                'away' => ['type' => 'platform.button', 'props' => ['text' => 'Go', 'href' => 'https://evil.example/']],
                'bound' => ['type' => 'platform.button', 'props' => ['text' => 'Go', 'href' => ['$data' => '/next']]],
                'list' => ['type' => 'platform.list', 'props' => ['items' => [['title' => 'Ok', 'href' => '/orders/1'], ['title' => 'Off', 'href' => '//evil.example']]]],
                'home' => ['type' => 'platform.button', 'props' => ['text' => 'Home', 'href' => '/orders?create']],
            ],
            'data' => ['next' => '/\\evil.example'],
        ];

        $result = $this->validator->check($doc);

        self::assertNull($result['tree']);
        self::assertSame(['tree.prop_invalid', 'tree.prop_invalid', 'tree.prop_invalid', 'tree.prop_invalid'], self::codes($result));
        self::assertSame(['/nodes/script/props/href', '/nodes/away/props/href', '/nodes/bound/props/href', '/nodes/list/props/items'], array_map(static fn ($e): string => $e->path, $result['errors']));
        self::assertStringContainsString('href must be a path on this site', $result['errors'][0]->message);
        self::assertStringContainsString('items[1].href must be a path on this site', $result['errors'][3]->message);
    }

    /** @param list<string> $permissions */
    private function grant(array $permissions): void
    {
        $user = new class implements AuthenticatableInterface {
            public function getId(): string { return 'u'; }
            public function getAuthIdentifierName(): string { return 'id'; }
            public function getAuthIdentifier(): mixed { return 'u'; }
        };
        $auth = new class($user) implements AuthContextInterface {
            public function __construct(private ?AuthenticatableInterface $user) {}
            public function getUser(): ?AuthenticatableInterface { return $this->user; }
            public function isGuest(): bool { return false; }
            public function setUser(?AuthenticatableInterface $user): void { $this->user = $user; }
            public static function get(): ?AuthContextInterface { return null; }
            public static function getOrFail(): AuthContextInterface { throw new \LogicException('not used'); }
        };
        UiPermissions::use($auth, new class($permissions) implements AuthorizerInterface {
            /** @param list<string> $granted */
            public function __construct(private array $granted) {}
            public function authorize(SubjectInterface $subject, AccessPolicy $policy): AccessDecision
            {
                return array_diff($policy->requiredPermissions, $this->granted) === []
                    ? AccessDecision::allow()
                    : AccessDecision::denyForbidden(DenyReason::PermissionRequired);
            }
        });
    }
}
