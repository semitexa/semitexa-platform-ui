<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Component;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Component\Builtin\FieldComponent;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentMetadataFactory;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\InputPrimitive;
use Semitexa\PlatformUi\Attribute\UiOn;
use Semitexa\PlatformUi\Attribute\UiPart;
use Semitexa\PlatformUi\Domain\Exception\UiComponentRegistryException;
use Semitexa\PlatformUi\Domain\Model\Component\UiOnMetadata;
use Semitexa\PlatformUi\Domain\Model\Component\UiValuePath;
use Semitexa\Ssr\Attribute\AsComponent;

final class UiOnMetadataTest extends TestCase
{
    private UiComponentMetadataFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new UiComponentMetadataFactory();
    }

    #[Test]
    public function field_component_exposes_input_change_event(): void
    {
        $metadata = $this->factory->fromClass(FieldComponent::class);

        $event = $metadata->event('input', 'change');
        self::assertInstanceOf(UiOnMetadata::class, $event);
        self::assertSame('platform.field', $event->componentName);
        self::assertSame(FieldComponent::class, $event->class);
        self::assertSame('input', $event->partName);
        self::assertSame('change', $event->eventName);
        self::assertSame('onInputChanged', $event->methodName);
        self::assertNotNull($event->updatesPath);
        self::assertSame('value', (string) $event->updatesPath);
    }

    #[Test]
    public function events_for_part_returns_only_matching_part_events(): void
    {
        $metadata = $this->factory->fromClass(FieldComponent::class);

        $inputEvents = $metadata->eventsForPart('input');
        self::assertCount(1, $inputEvents);
        self::assertSame('change', $inputEvents[0]->eventName);

        $unknownEvents = $metadata->eventsForPart('ghost');
        self::assertSame([], $unknownEvents);
    }

    #[Test]
    public function ui_on_referencing_unknown_part_is_rejected(): void
    {
        $this->expectException(UiComponentRegistryException::class);
        $this->expectExceptionMessageMatches('/no #\[UiPart\(name: "ghost"\)\] is declared/');

        $this->factory->fromClass(UnknownPartUiOnComponent::class);
    }

    /** @return iterable<string, array{0: string}> */
    public static function invalidEventNames(): iterable
    {
        yield 'empty'                  => [''];
        yield 'uppercase'              => ['Change'];
        yield 'has space'              => ['on change'];
        yield 'starts with digit'      => ['1click'];
        yield 'js code'                => ['onclick()'];
        yield 'twig expr'              => ['{{ value }}'];
        yield 'has bracket'            => ['click[0]'];
        yield 'has quote'              => ['click"'];
    }

    #[DataProvider('invalidEventNames')]
    #[Test]
    public function invalid_event_names_are_rejected(string $bad): void
    {
        $this->expectException(UiComponentRegistryException::class);
        $this->expectExceptionMessageMatches('/invalid event name/');

        // We can't directly instantiate the class with an attribute-string
        // parameter from a data provider, but we can build a metadata factory
        // call by simulating the attribute via reflection. The cleanest
        // approach is to define a small synthetic class per case via eval();
        // however, eval introduces test-runner friction. Instead, validate
        // by calling the factory on a class that hardcodes the value, then
        // mutate one event name dynamically.
        //
        // Simpler: pass each bad value through a tiny inline shim.
        $shim = new class extends \stdClass {};

        // Build a synthetic component fixture in-memory using anonymous-class
        // wrapping a real fixture would not let us re-use #[UiOn]. Instead,
        // rely on the per-bad-value fixture classes below.
        $cases = [
            ''             => UiOnEmptyEventComponent::class,
            'Change'       => UiOnUppercaseEventComponent::class,
            'on change'    => UiOnSpacedEventComponent::class,
            '1click'       => UiOnDigitFirstEventComponent::class,
            'onclick()'    => UiOnJsCodeEventComponent::class,
            '{{ value }}'  => UiOnTwigEventComponent::class,
            'click[0]'     => UiOnBracketEventComponent::class,
            'click"'       => UiOnQuoteEventComponent::class,
        ];
        self::assertArrayHasKey($bad, $cases, 'Missing fixture for bad event name: ' . var_export($bad, true));
        $this->factory->fromClass($cases[$bad]);
        unset($shim);
    }

    #[Test]
    public function invalid_updates_path_is_rejected(): void
    {
        $this->expectException(UiComponentRegistryException::class);
        $this->expectExceptionMessageMatches('/invalid path/');

        $this->factory->fromClass(UiOnInvalidUpdatesComponent::class);
    }

    #[Test]
    public function updates_strictly_must_match_bind_path_when_both_present(): void
    {
        $this->expectException(UiComponentRegistryException::class);
        $this->expectExceptionMessageMatches('/updates path must match the part bind path/');

        $this->factory->fromClass(UiOnUpdatesBindMismatchComponent::class);
    }

    #[Test]
    public function updates_omitted_inherits_part_bind_path(): void
    {
        $metadata = $this->factory->fromClass(UiOnUpdatesInheritedComponent::class);

        $event = $metadata->event('input', 'change');
        self::assertNotNull($event);
        self::assertInstanceOf(UiValuePath::class, $event->updatesPath);
        self::assertSame('user.email', (string) $event->updatesPath);
    }

    #[Test]
    public function updates_omitted_stays_null_when_part_has_no_bind(): void
    {
        $metadata = $this->factory->fromClass(UiOnNoBindNoUpdatesComponent::class);

        $event = $metadata->event('input', 'change');
        self::assertNotNull($event);
        self::assertNull($event->updatesPath);
    }

    #[Test]
    public function duplicate_part_event_pair_is_rejected(): void
    {
        $this->expectException(UiComponentRegistryException::class);
        $this->expectExceptionMessageMatches('/declares more than one #\[UiOn\(part: "input", event: "change"\)\]/');

        $this->factory->fromClass(UiOnDuplicatePartEventComponent::class);
    }

    #[Test]
    public function static_handler_is_rejected(): void
    {
        $this->expectException(UiComponentRegistryException::class);
        $this->expectExceptionMessageMatches('/must not be static/');

        $this->factory->fromClass(UiOnStaticHandlerComponent::class);
    }

    #[Test]
    public function two_events_on_different_methods_are_both_recorded(): void
    {
        $metadata = $this->factory->fromClass(UiOnTwoHandlersComponent::class);

        self::assertNotNull($metadata->event('input', 'change'));
        self::assertNotNull($metadata->event('input', 'blur'));
        self::assertCount(2, $metadata->eventsForPart('input'));
    }
}

#[AsComponent(name: 'platform.test-unknown-part-uion', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class, bind: 'value')]
final class UnknownPartUiOnComponent
{
    #[UiOn(part: 'ghost', event: 'change')]
    public function onGhost(array $event): void {}
}

#[AsComponent(name: 'platform.test-uion-bad-updates', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class)]
final class UiOnInvalidUpdatesComponent
{
    #[UiOn(part: 'input', event: 'change', updates: 'user..email')]
    public function onChange(array $event): void {}
}

#[AsComponent(name: 'platform.test-uion-mismatch', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class, bind: 'value')]
final class UiOnUpdatesBindMismatchComponent
{
    #[UiOn(part: 'input', event: 'change', updates: 'other.path')]
    public function onChange(array $event): void {}
}

#[AsComponent(name: 'platform.test-uion-inherit', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class, bind: 'user.email')]
final class UiOnUpdatesInheritedComponent
{
    #[UiOn(part: 'input', event: 'change')]
    public function onChange(array $event): void {}
}

#[AsComponent(name: 'platform.test-uion-no-bind', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class)]
final class UiOnNoBindNoUpdatesComponent
{
    #[UiOn(part: 'input', event: 'change')]
    public function onChange(array $event): void {}
}

#[AsComponent(name: 'platform.test-uion-dup', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class, bind: 'value')]
final class UiOnDuplicatePartEventComponent
{
    #[UiOn(part: 'input', event: 'change')]
    public function first(array $event): void {}

    #[UiOn(part: 'input', event: 'change')]
    public function second(array $event): void {}
}

#[AsComponent(name: 'platform.test-uion-static', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class, bind: 'value')]
final class UiOnStaticHandlerComponent
{
    #[UiOn(part: 'input', event: 'change')]
    public static function onChange(array $event): void {}
}

#[AsComponent(name: 'platform.test-uion-two', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class, bind: 'value')]
final class UiOnTwoHandlersComponent
{
    #[UiOn(part: 'input', event: 'change')]
    public function onChange(array $event): void {}

    #[UiOn(part: 'input', event: 'blur')]
    public function onBlur(array $event): void {}
}

// One synthetic component per invalid-event-name case (PHP attributes
// can't take dynamic arguments, so each case needs its own class).
#[AsComponent(name: 'platform.test-uion-empty', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class)]
final class UiOnEmptyEventComponent
{
    #[UiOn(part: 'input', event: '')]
    public function on(array $event): void {}
}

#[AsComponent(name: 'platform.test-uion-uppercase', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class)]
final class UiOnUppercaseEventComponent
{
    #[UiOn(part: 'input', event: 'Change')]
    public function on(array $event): void {}
}

#[AsComponent(name: 'platform.test-uion-spaced', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class)]
final class UiOnSpacedEventComponent
{
    #[UiOn(part: 'input', event: 'on change')]
    public function on(array $event): void {}
}

#[AsComponent(name: 'platform.test-uion-digitfirst', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class)]
final class UiOnDigitFirstEventComponent
{
    #[UiOn(part: 'input', event: '1click')]
    public function on(array $event): void {}
}

#[AsComponent(name: 'platform.test-uion-jscode', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class)]
final class UiOnJsCodeEventComponent
{
    #[UiOn(part: 'input', event: 'onclick()')]
    public function on(array $event): void {}
}

#[AsComponent(name: 'platform.test-uion-twig', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class)]
final class UiOnTwigEventComponent
{
    #[UiOn(part: 'input', event: '{{ value }}')]
    public function on(array $event): void {}
}

#[AsComponent(name: 'platform.test-uion-bracket', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class)]
final class UiOnBracketEventComponent
{
    #[UiOn(part: 'input', event: 'click[0]')]
    public function on(array $event): void {}
}

#[AsComponent(name: 'platform.test-uion-quote', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class)]
final class UiOnQuoteEventComponent
{
    #[UiOn(part: 'input', event: 'click"')]
    public function on(array $event): void {}
}
