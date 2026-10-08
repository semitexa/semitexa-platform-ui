<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Workbench;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Workbench\TwigLiteral;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * A copied Workbench snippet must reproduce the example exactly, so the
 * literal is checked by letting Twig itself evaluate it back.
 */
final class TwigLiteralTest extends TestCase
{
    #[Test]
    public function scalars_lists_and_maps_round_trip_through_twig(): void
    {
        $value = [
            'title' => "It's \"quoted\" \\ back",
            'count' => 3,
            'ratio' => 0.5,
            'on' => true,
            'off' => false,
            'none' => null,
            'items' => [['label' => 'Home', 'href' => '/'], ['label' => 'Jane']],
            'empty' => [],
            'data-x' => 'needs quoting',
        ];

        $twig = new Environment(new ArrayLoader(['t' => '{% set v = ' . TwigLiteral::export($value) . ' %}{{ v|json_encode|raw }}']));

        self::assertSame(json_encode($value), $twig->render('t'));
    }

    #[Test]
    public function identifiers_stay_bare_and_other_keys_are_quoted(): void
    {
        self::assertSame("{ title: 'A', 'data-x': 1 }", TwigLiteral::export(['title' => 'A', 'data-x' => 1]));
        self::assertSame("['a', 'b']", TwigLiteral::export(['a', 'b']));
    }
}
