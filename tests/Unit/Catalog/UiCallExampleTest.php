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

        $this->assertSame("{{ component('platform.stat', {\"label\":\"Orders\"}) }}", $call);
    }

    #[Test]
    public function a_component_with_slots_and_no_props_keeps_the_arguments_in_place(): void
    {
        $call = UiCallExample::for('component', 'platform.card', ['props' => [], 'slots' => ['body' => 'x']]);

        $this->assertSame(['component', 'platform.card', [], ['body' => 'x']], $this->renderedCall($call));
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
