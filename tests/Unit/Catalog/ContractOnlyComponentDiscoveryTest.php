<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Catalog;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentCatalog;

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
        foreach (['platform.button', 'platform.input', 'platform.badge'] as $primitive) {
            self::assertNotContains($primitive, $names);
        }
    }
}
