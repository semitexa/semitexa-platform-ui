<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\State;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\State\UiComponentStates;
use Semitexa\PlatformUi\Application\Service\State\UiComponentStateStoreInterface;
use Semitexa\PlatformUi\Attribute\UiState;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContextBinding;

/**
 * tk-ls-component-state: state is kept on the server when a component has
 * #[UiState] or its props are too large to copy into every context, under a
 * key only this visitor's session can name for this instance.
 */
final class UiComponentStatesTest extends TestCase
{
    private MemoryStateStore $store;

    protected function setUp(): void
    {
        $this->store = new MemoryStateStore();
        UiComponentStates::use($this->store);
        SignedContextBinding::bind('session-a', 'tenant-1');
    }

    protected function tearDown(): void
    {
        UiComponentStates::reset();
        SignedContextBinding::clear();
    }

    #[Test]
    public function a_stateful_component_is_kept_on_the_server_and_only_its_visitor_can_name_it(): void
    {
        $key = UiComponentStates::persist(StatefulFixture::class, 'uci_1', ['label' => 'Clicks', 'count' => 3, 'instanceId' => 'uci_1']);

        self::assertMatchesRegularExpression('/\A[0-9a-f]{40}\z/', (string) $key);
        self::assertSame(['label' => 'Clicks', 'count' => 3], UiComponentStates::load((string) $key, 'uci_1'), 'the instance id is not state');
        self::assertSame($key, UiComponentStates::persist(StatefulFixture::class, 'uci_1', ['count' => 4]), 'the same key on every re-render');
        self::assertSame(['count' => 4], UiComponentStates::load((string) $key, 'uci_1'));

        self::assertNull(UiComponentStates::load((string) $key, 'uci_2'), 'another instance cannot borrow it');
        SignedContextBinding::bind('session-b', 'tenant-1');
        self::assertNull(UiComponentStates::load((string) $key, 'uci_1'), 'another visitor cannot name it');
    }

    #[Test]
    public function small_props_of_a_stateless_component_stay_inline_and_large_ones_do_not(): void
    {
        self::assertNull(UiComponentStates::persist(\stdClass::class, 'uci_1', ['label' => 'x']));
        self::assertSame([], $this->store->items, 'no store round trip for the common case');

        $large = ['rows' => str_repeat('x', UiComponentStates::INLINE_LIMIT_BYTES + 1)];
        self::assertNotNull(UiComponentStates::persist(\stdClass::class, 'uci_1', $large), 'too large to copy into every context');

        $huge = ['rows' => str_repeat('x', UiComponentStates::MAX_BYTES + 1)];
        self::assertNull(UiComponentStates::persist(StatefulFixture::class, 'uci_1', $huge), 'past the limit: not kept');

        SignedContextBinding::clear();
        self::assertNull(UiComponentStates::persist(StatefulFixture::class, 'uci_1', ['count' => 1]), 'no session: nothing to key it by');

        SignedContextBinding::bind('session-a', 'tenant-1');
        $this->store->shared = false;
        self::assertNull(UiComponentStates::persist(StatefulFixture::class, 'uci_1', ['count' => 1]), 'a store only this worker sees: inline instead');
    }

    #[Test]
    public function the_state_properties_are_set_from_the_saved_state_in_their_declared_types(): void
    {
        $component = new StatefulFixture();
        $before = UiComponentStates::hydrate($component, ['count' => '7', 'open' => 'true', 'label' => 'ignored']);

        self::assertSame(['count' => 7, 'open' => true], $before);
        $component->count++;
        self::assertSame(['count' => 8, 'open' => true], UiComponentStates::snapshot($component));
        self::assertSame(['count', 'open'], UiComponentStates::propertiesOf(StatefulFixture::class), 'only #[UiState], public');
    }

    #[Test]
    public function a_saved_value_the_declared_type_cannot_hold_is_skipped_not_thrown(): void
    {
        $component = new StatefulFixture();

        self::assertSame(['count' => 0, 'open' => true], UiComponentStates::hydrate($component, ['count' => null, 'open' => true]));
        self::assertSame(['count' => 0, 'open' => true], UiComponentStates::hydrate($component, ['count' => 'abc']));
        self::assertSame(['count' => 0, 'open' => true], UiComponentStates::hydrate($component, ['count' => ['nested']]));
    }
}

final class StatefulFixture
{
    #[UiState]
    public int $count = 0;

    #[UiState]
    public bool $open = false;

    #[UiState]
    private int $hidden = 0;

    public string $label = '';
}

final class MemoryStateStore implements UiComponentStateStoreInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $items = [];

    public function get(string $key): ?array
    {
        return $this->items[$key] ?? null;
    }

    public function put(string $key, array $props, int $ttlSeconds): void
    {
        $this->items[$key] = $props;
    }

    public bool $shared = true;

    public function isShared(): bool
    {
        return $this->shared;
    }
}
