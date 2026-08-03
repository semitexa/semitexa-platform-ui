<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\PlatformUi\Application\Service\Behavior\UiBehaviorCatalog;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentCatalog;
use Semitexa\PlatformUi\Application\Service\Primitive\UiPrimitiveCatalog;

/**
 * A catalog queried before its ClassDiscovery is wired must still discover
 * afterwards.
 *
 * All three catalogs are lazily initialised — get(), has() and all() call
 * initialize() themselves. That makes the ORDER of first use and boot wiring
 * load-bearing: anything that asks a question early (a lifecycle listener, a
 * warmup, a CLI path, a test bootstrap) triggers an initialize() that finds no
 * discovery yet. If that early pass latches `initialized`, the catalog is empty
 * for the rest of the worker's life — and empty is not an error, so nothing
 * reports it. The page simply renders without its primitives.
 *
 * UiBehaviorCatalog already got this right and said so in a comment;
 * UiPrimitiveCatalog latched. Measured before the fix: primitive 0 entries,
 * behavior 10. Nothing bit today only because nothing happened to query
 * primitives early — an ordering accident, not a guarantee.
 *
 * Parameterised over all three so a fourth catalog cannot quietly reintroduce
 * the latch.
 */
final class CatalogLateWiringTest extends TestCase
{
    /**
     * @return array<string, array{callable(): object}>
     */
    public static function catalogs(): array
    {
        return [
            'primitive' => [static fn (): object => new UiPrimitiveCatalog()],
            'behavior' => [static fn (): object => new UiBehaviorCatalog()],
            'component' => [static fn (): object => new UiComponentCatalog()],
        ];
    }

    #[Test]
    #[DataProvider('catalogs')]
    public function an_early_query_does_not_stop_later_discovery(callable $make): void
    {
        $catalog = $make();

        // Somebody asks before the boot listener has wired anything.
        self::assertSame([], $catalog->all(), 'an unwired catalog starts empty');

        // The boot listener now wires discovery and initialises.
        $catalog->setClassDiscovery(self::discovery());
        $catalog->initialize();

        self::assertNotSame(
            [],
            $catalog->all(),
            'the catalog latched empty on the early query and never discovered — '
            . 'in a worker this means the feature silently renders with nothing, and no error is raised',
        );
    }

    #[Test]
    #[DataProvider('catalogs')]
    public function wiring_before_the_first_query_still_works(callable $make): void
    {
        // The ordinary order, kept as the control: the fix must not achieve
        // late discovery by breaking the normal path.
        $catalog = $make();
        $catalog->setClassDiscovery(self::discovery());

        self::assertNotSame([], $catalog->all());
    }

    #[Test]
    #[DataProvider('catalogs')]
    public function discovery_runs_once_even_when_initialize_is_called_repeatedly(callable $make): void
    {
        // The counterpart to dropping the latch: initialize() is called lazily
        // by every read, so it must stay idempotent once discovery HAS run.
        // Without this, every get() would re-scan and registerInternal() would
        // throw on its own duplicates.
        $catalog = $make();
        $catalog->setClassDiscovery(self::discovery());
        $catalog->initialize();
        $first = $catalog->all();

        $catalog->initialize();
        $catalog->initialize();

        self::assertSame(
            count($first),
            count($catalog->all()),
            'repeated initialize() re-ran discovery; duplicate registration would throw',
        );
    }

    private static function discovery(): ClassDiscovery
    {
        static $discovery = null;
        if ($discovery === null) {
            $discovery = new ClassDiscovery();
            $discovery->initialize();
        }

        return $discovery;
    }
}
