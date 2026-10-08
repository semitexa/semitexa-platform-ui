<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Component;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Event\UiEventManifestBuilder;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\InputPrimitive;
use Semitexa\PlatformUi\Attribute\UiOn;
use Semitexa\PlatformUi\Attribute\UiPart;
use Semitexa\PlatformUi\Domain\Exception\UiComponentRegistryException;
use Semitexa\Ssr\Attribute\AsComponent;

/**
 * tk-la-input-timing: #[UiOn(debounce:|throttle:)] tells the browser WHEN to
 * send; the manifest carries it as `d` / `t`, and nonsense is refused at boot.
 */
final class UiOnTimingTest extends TestCase
{
    private string|false $previousSecret = false;

    private string|false $previousEnv = false;

    protected function setUp(): void
    {
        $this->previousSecret = getenv('APP_SECRET');
        $this->previousEnv = getenv('APP_ENV');
        putenv('APP_SECRET=ui-on-timing-test');
        putenv('APP_ENV=dev');
    }

    protected function tearDown(): void
    {
        putenv($this->previousSecret === false ? 'APP_SECRET' : 'APP_SECRET=' . $this->previousSecret);
        putenv($this->previousEnv === false ? 'APP_ENV' : 'APP_ENV=' . $this->previousEnv);
    }

    #[Test]
    public function the_manifest_carries_debounce_and_throttle(): void
    {
        $metadata = (new UiComponentMetadataFactory())->fromClass(TimedFixture::class);
        $events = (new UiEventManifestBuilder())->build(metadata: $metadata, instanceId: 'uci_timing_0001')->toJsonShape()['events'];
        $byEvent = array_column($events, null, 'e');

        self::assertSame(300, $byEvent['input']['d']);
        self::assertArrayNotHasKey('t', $byEvent['input']);
        self::assertSame(1000, $byEvent['scroll']['t']);
        self::assertArrayNotHasKey('d', $byEvent['change']);
    }

    #[Test]
    public function an_out_of_range_timing_is_refused_at_boot(): void
    {
        $this->expectException(UiComponentRegistryException::class);
        $this->expectExceptionMessageMatches('/1\.\.10000 ms/');
        (new UiComponentMetadataFactory())->fromClass(OutOfRangeFixture::class);
    }

    #[Test]
    public function debounce_and_throttle_together_are_refused(): void
    {
        $this->expectException(UiComponentRegistryException::class);
        $this->expectExceptionMessageMatches('/both debounce and throttle/');
        (new UiComponentMetadataFactory())->fromClass(BothFixture::class);
    }
}

#[AsComponent(name: 'timing.fixture', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class)]
final class TimedFixture
{
    #[UiOn(part: 'input', event: 'input', debounce: 300)]
    public function onInput(): void {}

    #[UiOn(part: 'input', event: 'scroll', throttle: 1000)]
    public function onScroll(): void {}

    #[UiOn(part: 'input', event: 'change')]
    public function onChange(): void {}
}

#[AsComponent(name: 'timing.out-of-range', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class)]
final class OutOfRangeFixture
{
    #[UiOn(part: 'input', event: 'input', debounce: 60000)]
    public function onInput(): void {}
}

#[AsComponent(name: 'timing.both', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class)]
final class BothFixture
{
    #[UiOn(part: 'input', event: 'input', debounce: 100, throttle: 100)]
    public function onInput(): void {}
}
