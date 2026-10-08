<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Workbench;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Semitexa\PlatformUi\Application\Component\Builtin\EmptyStateComponent;
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

    #[Test]
    public function a_behavior_example_without_a_template_is_not_rendered_as_a_component(): void
    {
        $metadata = (new UiBehaviorMetadataFactory())->fromClass(DropdownBehavior::class);
        $item = new UiCatalogItem('behavior', $metadata, null, null, '', 0, '');

        $view = self::example($item, new UiExample('plain', 'Plain'));

        self::assertSame(['name' => 'plain', 'label' => 'Plain', 'uid' => 'wb-dropdown-plain', 'render' => 'none', 'snippet' => ''], $view);
    }
}
