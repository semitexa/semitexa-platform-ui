<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Behavior;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Behavior\Builtin\AccordionBehavior;
use Semitexa\PlatformUi\Application\Service\Behavior\Builtin\DropdownBehavior;
use Semitexa\PlatformUi\Application\Service\Behavior\Builtin\ModalBehavior;
use Semitexa\PlatformUi\Application\Service\Behavior\Builtin\TabsBehavior;
use Semitexa\PlatformUi\Application\Service\Behavior\Builtin\ToggleBehavior;
use Semitexa\PlatformUi\Application\Service\Behavior\Builtin\TooltipBehavior;
use Semitexa\PlatformUi\Application\Service\Behavior\UiBehaviorMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Behavior\UiBehaviorRegistry;
use Semitexa\PlatformUi\Domain\Model\Behavior\UiOptionType;

final class BuiltinBehaviorsTest extends TestCase
{
    protected function tearDown(): void
    {
        UiBehaviorRegistry::reset();
    }

    #[Test]
    public function builtin_behaviors_register_with_expected_identity_and_options(): void
    {
        $factory = new UiBehaviorMetadataFactory();
        UiBehaviorRegistry::register($factory->fromClass(ToggleBehavior::class));
        UiBehaviorRegistry::register($factory->fromClass(DropdownBehavior::class));

        $toggle = UiBehaviorRegistry::getByUi('toggle');
        self::assertNotNull($toggle);
        self::assertSame('platform.toggle', $toggle->name);
        self::assertSame('platform-ui:js:behaviors', $toggle->script);
        self::assertTrue($toggle->declaresA11y('aria-expanded'));

        $dropdown = UiBehaviorRegistry::getByName('platform.dropdown');
        self::assertNotNull($dropdown);
        self::assertSame('dropdown', $dropdown->ui);

        $mode = $dropdown->option('mode');
        self::assertNotNull($mode);
        self::assertSame(UiOptionType::Enum, $mode->type);
        self::assertSame('click', $mode->default);
        self::assertContains('hover', $mode->values);

        $offset = $dropdown->option('offset');
        self::assertNotNull($offset);
        self::assertSame(UiOptionType::Number, $offset->type);
        self::assertSame(4, $offset->default);

        // The a11y capability manifest the showcase will assert against.
        self::assertTrue($dropdown->declaresA11y('focus-trap'));
        self::assertTrue($dropdown->declaresA11y('esc-dismiss'));
    }

    #[Test]
    public function dropdown_client_descriptor_ships_the_coercion_table(): void
    {
        $factory = new UiBehaviorMetadataFactory();
        $descriptor = $factory->fromClass(DropdownBehavior::class)->toDescriptor();

        self::assertSame('dropdown', $descriptor['ui']);
        $names = array_column($descriptor['options'], 'name');
        self::assertSame(['mode', 'pos', 'offset', 'flip'], $names);
        // pos values are logical (RTL-correct) sides
        $pos = $descriptor['options'][1];
        self::assertContains('bottom-start', $pos['values']);
        self::assertContains('top-end', $pos['values']);
    }

    #[Test]
    public function f2_interactive_behaviors_register_with_expected_identity_and_a11y(): void
    {
        $factory = new UiBehaviorMetadataFactory();
        foreach ([AccordionBehavior::class, TabsBehavior::class, TooltipBehavior::class, ModalBehavior::class] as $class) {
            UiBehaviorRegistry::register($factory->fromClass($class));
        }

        $accordion = UiBehaviorRegistry::getByUi('accordion');
        self::assertNotNull($accordion);
        self::assertSame('platform.accordion', $accordion->name);
        self::assertNotNull($accordion->option('multiple'));

        $tabs = UiBehaviorRegistry::getByUi('tabs');
        self::assertNotNull($tabs);
        self::assertTrue($tabs->declaresA11y('roving-tabindex'));

        $tooltip = UiBehaviorRegistry::getByUi('tooltip');
        self::assertNotNull($tooltip);
        self::assertTrue($tooltip->declaresA11y('aria-describedby'));
        self::assertSame('top', $tooltip->option('pos')?->default);

        $modal = UiBehaviorRegistry::getByName('platform.modal');
        self::assertNotNull($modal);
        self::assertTrue($modal->declaresA11y('focus-trap'));
        self::assertTrue($modal->declaresA11y('scroll-lock'));
    }
}
