<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Workbench;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Semitexa\PlatformUi\Application\Component\Builtin\EmptyStateComponent;
use Semitexa\PlatformUi\Application\Component\Builtin\StatComponent;
use Semitexa\PlatformUi\Application\Service\Behavior\Builtin\DropdownBehavior;
use Semitexa\PlatformUi\Application\Service\Behavior\UiBehaviorMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Workbench\WorkbenchViewBuilder;
use Semitexa\PlatformUi\Domain\Model\Contract\UiCatalogItem;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;

final class WorkbenchViewBuilderTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function example(UiCatalogItem $item, UiExample $example): array
    {
        /** @var array<string, mixed> */
        return (new ReflectionMethod(WorkbenchViewBuilder::class, 'example'))->invoke(new WorkbenchViewBuilder(), $item, $example);
    }

    #[Test]
    public function the_copied_twig_carries_the_slot_text_the_stage_shows(): void
    {
        $metadata = (new UiComponentMetadataFactory())->fromClass(EmptyStateComponent::class);
        $item = new UiCatalogItem('component', $metadata, null, null, '', 0, '');

        $view = self::example($item, new UiExample('slot', 'Slot', ['title' => 'Empty'], ['actions' => '<em>Save</em>']));

        self::assertSame('component', $view['render']);
        self::assertSame(['actions' => '&lt;em&gt;Save&lt;/em&gt;'], $view['slots']);
        self::assertSame("{{ component('platform.empty-state', { title: 'Empty' }, { actions: '&lt;em&gt;Save&lt;/em&gt;' }) }}", $view['snippet']);
    }

    /**
     * @param array<string, string> $try
     * @return array<string, mixed>|null
     */
    private static function tryPanel(array $try): ?array
    {
        $metadata = (new UiComponentMetadataFactory())->fromClass(StatComponent::class);
        $item = new UiCatalogItem('component', $metadata, $metadata->contract, null, '', 0, '');

        /** @var array<string, mixed>|null */
        return (new ReflectionMethod(WorkbenchViewBuilder::class, 'tryPanel'))->invoke(new WorkbenchViewBuilder(), $item, $try);
    }

    #[Test]
    public function the_try_panel_offers_a_control_per_scalar_prop_and_renders_nothing_until_asked(): void
    {
        $panel = self::tryPanel([]);

        self::assertNotNull($panel);
        self::assertSame(['label', 'value', 'delta', 'trend', 'caption'], array_column($panel['controls'], 'name'));
        $trend = $panel['controls'][3];
        self::assertSame(['up', 'down', 'flat'], $trend['values']);
        self::assertNull($panel['example']);
        self::assertSame('', $panel['error']);
    }

    #[Test]
    public function typed_values_render_over_the_first_example(): void
    {
        $panel = self::tryPanel(['trend' => 'down', 'label' => 'Churn', 'value' => '']);

        self::assertNotNull($panel);
        self::assertSame('', $panel['error']);
        self::assertSame('component', $panel['example']['render']);
        self::assertSame('down', $panel['example']['props']['trend']);
        self::assertSame('Churn', $panel['example']['props']['label']);
        // An emptied control keeps the example's own value instead of dropping a required prop.
        self::assertNotSame('', $panel['example']['props']['value'] ?? '');
    }

    #[Test]
    public function a_value_the_contract_refuses_is_named_instead_of_rendered(): void
    {
        $panel = self::tryPanel(['trend' => 'sideways']);

        self::assertNotNull($panel);
        self::assertNull($panel['example']);
        self::assertSame('props.trend is not one of the declared values.', $panel['error']);
    }

    #[Test]
    public function the_structure_hangs_events_off_their_part_and_keeps_the_rest_on_the_component(): void
    {
        $structure = (new ReflectionMethod(WorkbenchViewBuilder::class, 'structure'))->invoke(null, [
            'parts' => (object) ['input' => ['primitive' => 'platform.input', 'bind' => 'value', 'provider' => 'Field::inputPart']],
            'slots' => (object) ['prefix' => ['description' => 'Before the input.']],
            'events' => (object) [
                'input.change' => ['part' => 'input', 'event' => 'change', 'handler' => 'Field::onChange', 'transport' => 'hug'],
                'root.submit' => ['part' => 'root', 'event' => 'submit', 'handler' => 'Field::onSubmit'],
            ],
        ]);

        self::assertSame([[
            'name' => 'input', 'primitive' => 'platform.input', 'bind' => 'value', 'provider' => 'Field::inputPart',
            'events' => [['event' => 'change', 'handler' => 'Field::onChange', 'transport' => 'hug']],
        ]], $structure['parts']);
        self::assertSame([['name' => 'prefix', 'description' => 'Before the input.']], $structure['slots']);
        self::assertSame([['event' => 'submit', 'handler' => 'Field::onSubmit', 'transport' => '']], $structure['events']);
        self::assertNull((new ReflectionMethod(WorkbenchViewBuilder::class, 'structure'))->invoke(null, ['parts' => (object) [], 'slots' => (object) []]));
    }

    #[Test]
    public function a_behavior_example_without_a_template_is_not_rendered_as_a_component(): void
    {
        $metadata = (new UiBehaviorMetadataFactory())->fromClass(DropdownBehavior::class);
        $item = new UiCatalogItem('behavior', $metadata, null, null, '', 0, '');

        $view = self::example($item, new UiExample('plain', 'Plain'));

        self::assertSame(['name' => 'plain', 'label' => 'Plain', 'uid' => 'wb-dropdown-plain', 'render' => 'none', 'snippet' => ''], $view);
    }
}
