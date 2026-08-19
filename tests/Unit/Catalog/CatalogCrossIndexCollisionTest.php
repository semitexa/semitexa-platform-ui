<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Catalog;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveCatalog;
use Semitexa\PlatformUi\Domain\Exception\PrimitiveRegistryException;
use Semitexa\PlatformUi\Domain\Model\Primitive\PrimitiveMetadata;

/**
 * The cross-index invariant `AbstractUiCatalog::registerInternal()` enforces:
 * one string must never be a NAME in one entry and an ALIAS in another. Before
 * the guard, `get()` (byName first) and `getByUi()` disagreed about who owned
 * such a string — surfaced by the PR #21 review, and pre-existing in both twin
 * catalogs verbatim, which is exactly why the shared base fixes it once.
 *
 * An entry whose own `ui` equals its own `name` stays legal, as does
 * idempotent re-registration of an identical entry.
 */
final class CatalogCrossIndexCollisionTest extends TestCase
{
    private static function metadata(string $class, string $name, string $ui): PrimitiveMetadata
    {
        return new PrimitiveMetadata(
            class: $class,
            name: $name,
            ui: $ui,
            template: null,
            script: null,
            style: null,
            events: [],
        );
    }

    #[Test]
    public function a_name_equal_to_an_existing_alias_is_rejected(): void
    {
        $catalog = new UiPrimitiveCatalog();
        $catalog->register(self::metadata('App\\A', 'platform.menu', 'menu'));

        $this->expectException(PrimitiveRegistryException::class);
        $this->expectExceptionMessageMatches('/name "menu" conflicts with the alias/');

        $catalog->register(self::metadata('App\\B', 'menu', 'menu-b'));
    }

    #[Test]
    public function an_alias_equal_to_an_existing_name_is_rejected(): void
    {
        $catalog = new UiPrimitiveCatalog();
        $catalog->register(self::metadata('App\\A', 'menu', 'sx-menu'));

        $this->expectException(PrimitiveRegistryException::class);
        $this->expectExceptionMessageMatches('/alias "menu" conflicts with the name/');

        $catalog->register(self::metadata('App\\B', 'platform.menu', 'menu'));
    }

    #[Test]
    public function an_entry_aliased_by_its_own_name_stays_legal_and_re_registers_idempotently(): void
    {
        $catalog = new UiPrimitiveCatalog();
        $selfAliased = self::metadata('App\\A', 'menu', 'menu');

        $catalog->register($selfAliased);
        $catalog->register($selfAliased);

        self::assertSame('App\\A', $catalog->get('menu')?->class);
        self::assertSame('App\\A', $catalog->getByUi('menu')?->class);
        self::assertCount(1, $catalog->all());
    }
}
