<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Event\UiInteractionDispatcher;
use Semitexa\PlatformUi\Application\Service\Event\UiLostProps;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionUnprocessableException;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionResult;
use Semitexa\PlatformUi\Domain\Model\Event\UiResponsePatch;

/**
 * A component whose props could not be kept was redrawn with none: a list came
 * back empty, with no error. The dispatcher is driven for real, up to the morph.
 */
final class UiLostPropsTest extends TestCase
{
    private const INSTANCE = 'uci_lost_001';

    #[Test]
    public function a_rerender_that_would_miss_a_prop_is_refused_by_name(): void
    {
        try {
            $this->rerender(['pl' => ['rows', 'title']], ['title' => 'New']);
            self::fail('the list would have been drawn empty');
        } catch (UiInteractionUnprocessableException $refused) {
            self::assertStringContainsString('rows', $refused->getMessage());
            self::assertStringNotContainsString('title', $refused->getMessage());
        }
    }

    #[Test]
    public function a_handler_that_passes_every_lost_prop_rerenders(): void
    {
        self::assertSame(['rows' => [1], 'instanceId' => self::INSTANCE], $this->rerender(['pl' => ['rows']], ['rows' => [1]]));
    }

    #[Test]
    public function a_data_provider_draws_them_itself(): void
    {
        self::assertSame(['instanceId' => self::INSTANCE], $this->rerender(['pl' => ['rows'], 'dp' => 'App\\Provider'], []));
    }

    #[Test]
    public function nothing_lost_changes_nothing(): void
    {
        self::assertSame(['title' => 'Old', 'instanceId' => self::INSTANCE], $this->rerender(['pr' => ['title' => 'Old']], []));
    }

    #[Test]
    public function the_instance_id_is_not_a_prop_to_lose(): void
    {
        self::assertSame(['rows', '0'], UiLostProps::namesOf(['instanceId' => 'x', 'rows' => new \stdClass(), 0 => 'y']));
        self::assertSame([], UiLostProps::namesOf([]));
    }

    /**
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $overrides
     * @return array<string, mixed> the props the component was drawn with
     */
    private function rerender(array $claims, array $overrides): array
    {
        $drawnWith = null;
        $dispatcher = (new \ReflectionClass(UiInteractionDispatcher::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(UiInteractionDispatcher::class, 'componentRenderer'))->setValue(
            $dispatcher,
            static function (string $name, array $props) use (&$drawnWith): string {
                $drawnWith = $props;

                return '<div></div>';
            },
        );

        (new \ReflectionMethod(UiInteractionDispatcher::class, 'resolveRerenders'))->invoke(
            $dispatcher,
            UiInteractionResult::patch([UiResponsePatch::rerender(self::INSTANCE, $overrides)]),
            self::INSTANCE,
            'test.list',
            $claims,
        );

        return (array) $drawnWith;
    }
}
