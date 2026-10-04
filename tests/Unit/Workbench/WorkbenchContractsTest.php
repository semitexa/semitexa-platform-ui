<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Workbench;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Semitexa\PlatformUi\Application\Service\Css\PrimitiveRegistry;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\AlertPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\BadgePrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\ButtonPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\InputPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\SpinnerPrimitive;
use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;

final class WorkbenchContractsTest extends TestCase
{
    /** @return iterable<string, array{class-string, string}> */
    public static function primitives(): iterable
    {
        yield 'button' => [ButtonPrimitive::class, 'button'];
        yield 'badge' => [BadgePrimitive::class, 'badge'];
        yield 'alert' => [AlertPrimitive::class, 'alert'];
        yield 'input' => [InputPrimitive::class, 'input'];
        yield 'spinner' => [SpinnerPrimitive::class, 'spinner'];
    }

    /**
     * The renderer checks variant/tone/size against PrimitiveRegistry; the
     * Workbench, the CLI catalog and the AI prompt read the contract. Two
     * lists of the same thing drift unless something compares them.
     *
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('primitives')]
    public function contract_enums_match_the_render_vocabulary(string $class, string $ui): void
    {
        $contract = (new ReflectionClass($class))->getAttributes(AsUiContract::class)[0]->newInstance()->metadata();
        $vocabulary = (new PrimitiveRegistry())->get($ui);
        self::assertNotNull($vocabulary);

        foreach (['variant' => $vocabulary->variants, 'tone' => $vocabulary->tones, 'size' => $vocabulary->sizes] as $prop => $declared) {
            if ($declared === []) {
                self::assertArrayNotHasKey($prop, $contract->props, "{$ui}: contract declares {$prop} the renderer does not know");
                continue;
            }
            self::assertArrayHasKey($prop, $contract->props, "{$ui}: renderer vocabulary has {$prop}, contract does not");
            $values = $contract->props[$prop]->values;
            sort($values);
            sort($declared);
            self::assertSame($declared, $values, "{$ui}.{$prop}");
        }
    }

    #[Test]
    public function an_example_template_is_a_namespaced_package_path(): void
    {
        $ok = new UiExample('menu', 'Menu', [], [], '@platform-ui/examples/dropdown.html.twig');
        self::assertSame('@platform-ui/examples/dropdown.html.twig', $ok->toArray()['template']);

        foreach (['examples/x.html.twig', '@platform-ui/../secrets.twig', '@platform-ui/x.php', '<b>inline</b>'] as $bad) {
            try {
                new UiExample('x', 'X', [], [], $bad);
                self::fail("accepted {$bad}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
