<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Catalog;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Catalog\UiCallExample;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFunction;

final class UiCallExampleTest extends TestCase
{
    #[Test]
    public function a_component_call_compiles_and_hands_over_the_example_as_it_stands(): void
    {
        $props = ['title' => "It's \"quoted\"", 'count' => 2, 'on' => true, 'nothing' => null, 'list' => ['a', 'b'], 'nested' => ['x' => 1]];
        $slots = ['body' => 'Build something.'];

        $call = UiCallExample::for('component', 'platform.card', ['props' => $props, 'slots' => $slots]);

        $this->assertSame(['component', 'platform.card', $props, $slots], $this->renderedCall($call));
    }

    #[Test]
    public function a_component_without_slots_passes_props_alone(): void
    {
        $call = UiCallExample::for('component', 'platform.stat', ['props' => ['label' => 'Orders'], 'slots' => []]);

        $this->assertSame("{{ component('platform.stat', {'label': 'Orders'}) }}", $call);
    }

    #[Test]
    public function a_component_with_slots_and_no_props_keeps_the_arguments_in_place(): void
    {
        $call = UiCallExample::for('component', 'platform.card', ['props' => [], 'slots' => ['body' => 'x']]);

        $this->assertSame(['component', 'platform.card', [], ['body' => 'x']], $this->renderedCall($call));
    }

    #[Test]
    public function text_twig_would_read_as_code_reaches_the_component_as_text(): void
    {
        $props = ['interpolated' => '#{1 + 2}', 'control' => "a\x01b", 'quote' => "it's", 'slash' => 'a\\b', 'unicode' => 'привіт', 'tag' => '{{ x }}', 'float' => 1.5];

        $call = UiCallExample::for('component', 'platform.card', ['props' => $props, 'slots' => ['body' => '#{y}']]);

        $this->assertSame(['component', 'platform.card', $props, ['body' => '#{y}']], $this->renderedCall($call));
    }

    #[Test]
    public function an_object_prop_keeps_its_text_as_it_stands(): void
    {
        // As UiProp::normalizeObject() hands them over. The dash is from a real contract.
        $props = (object) ['answer' => 'Yes — find-in-page', 'code' => '#{1+2}', 'nested' => (object) ['n' => 1]];

        $call = UiCallExample::for('component', 'platform.faq', ['props' => ['item' => $props], 'slots' => []]);

        $this->assertSame(
            ['component', 'platform.faq', ['item' => ['answer' => 'Yes — find-in-page', 'code' => '#{1+2}', 'nested' => ['n' => 1]]]],
            $this->renderedCall($call),
        );
    }

    #[Test]
    public function a_float_stays_a_float(): void
    {
        $call = UiCallExample::for('component', 'x', ['props' => ['a' => 1.0, 'b' => 0.0, 'c' => 100000.0, 'd' => 1.5], 'slots' => []]);

        $this->assertSame(['component', 'x', ['a' => 1.0, 'b' => 0.0, 'c' => 100000.0, 'd' => 1.5]], $this->renderedCall($call));
    }

    #[Test]
    public function a_carriage_return_survives_twig_normalising_its_source(): void
    {
        $props = ['windows' => "a\r\nb", 'mac' => "a\rb", 'mixed' => "it's \"q\" \\ #{x}\r"];

        $call = UiCallExample::for('component', 'x', ['props' => $props, 'slots' => []]);

        $this->assertSame(['component', 'x', $props], $this->renderedCall($call));
    }

    #[Test]
    public function an_empty_object_and_an_empty_list_keep_their_shape(): void
    {
        $this->assertStringContainsString("'o': {}", UiCallExample::for('component', 'x', ['props' => ['o' => new \stdClass()], 'slots' => []]));
        $this->assertStringContainsString("'l': []", UiCallExample::for('component', 'x', ['props' => ['l' => []], 'slots' => []]));
    }

    #[Test]
    public function a_primitive_is_called_as_a_primitive(): void
    {
        $call = UiCallExample::for('primitive', 'platform.button', ['props' => ['text' => 'Save'], 'slots' => []]);

        $this->assertSame(['primitive', 'platform.button', ['text' => 'Save']], $this->renderedCall($call));
    }

    #[Test]
    public function an_example_that_is_markup_in_a_file_has_no_call(): void
    {
        $this->assertSame('', UiCallExample::for('behavior', 'platform.accordion', ['props' => [], 'slots' => [], 'template' => '@x/a.twig']));
    }

    /** @return list<mixed> What the Twig functions received, proving the call parses and carries the data. */
    private function renderedCall(string $call): array
    {
        $received = [];
        $twig = new Environment(new ArrayLoader(['t' => $call]), ['autoescape' => false]);
        foreach (['component', 'primitive'] as $function) {
            $twig->addFunction(new TwigFunction($function, static function (mixed ...$arguments) use (&$received, $function): string {
                $received = [$function, ...$arguments];

                return '';
            }));
        }
        $twig->render('t');

        return $received;
    }
}
