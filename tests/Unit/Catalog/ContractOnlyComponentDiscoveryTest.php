<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Catalog;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentCatalog;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\BadgePrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\ButtonPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\TextareaPrimitive;
use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Attribute\AsUiPrimitive;

/**
 * A component that declares a contract but no parts or slots — a sign-in
 * form, a page block — is in the component catalog (and so in the Workbench);
 * a primitive's contract does not make the primitive a component.
 */
final class ContractOnlyComponentDiscoveryTest extends TestCase
{
    #[Test]
    public function contract_only_components_are_discovered_and_primitives_are_not(): void
    {
        $discovery = new ClassDiscovery();
        $discovery->initialize();
        $catalog = new UiComponentCatalog();
        $catalog->setClassDiscovery($discovery);

        $names = array_map(static fn ($m): string => $m->name, $catalog->all());

        foreach (['platform.sign-in', 'platform.appearance-settings', 'platform.block-pricing', 'platform.block-faq', 'platform.block-hero'] as $expected) {
            self::assertContains($expected, $names);
        }
        // The primitives below carry #[AsUiContract], so discovery hands them
        // to the catalog: their absence from it is the filter, not an empty list.
        $contracted = $discovery->findClassesWithAttribute(AsUiContract::class);
        foreach ([ButtonPrimitive::class => 'platform.button', TextareaPrimitive::class => 'platform.textarea', BadgePrimitive::class => 'platform.badge'] as $class => $primitive) {
            self::assertContains($class, $contracted);
            self::assertSame($primitive, (new \ReflectionClass($class))->getAttributes(AsUiPrimitive::class)[0]->newInstance()->name);
            self::assertNotContains($primitive, $names);
        }
    }
}
