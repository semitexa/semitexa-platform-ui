<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Workbench;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Semitexa\PlatformUi\Application\Component\Builtin\BreadcrumbComponent;
use Semitexa\PlatformUi\Application\Component\Builtin\NavbarComponent;
use Semitexa\PlatformUi\Application\Component\Builtin\PaginationComponent;
use Semitexa\PlatformUi\Application\Component\Builtin\TableComponent;
use Semitexa\PlatformUi\Application\Service\Css\PrimitiveRegistry;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\AlertPrimitive;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\AvatarPrimitive;
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

    /**
     * A contract is open to an AI-composed screen unless it says otherwise.
     * These exist for the Workbench; some render hrefs without ui_href, so
     * opening them to agents is a decision of its own, not a side effect.
     *
     * @return iterable<string, array{class-string}>
     */
    public static function workbenchOnlyContracts(): iterable
    {
        yield 'avatar' => [AvatarPrimitive::class];
        yield 'spinner' => [SpinnerPrimitive::class];
        yield 'input' => [InputPrimitive::class];
        yield 'breadcrumb' => [BreadcrumbComponent::class];
        yield 'navbar' => [NavbarComponent::class];
        yield 'pagination' => [PaginationComponent::class];
        yield 'table' => [TableComponent::class];
    }

    /** @param class-string $class */
    #[Test]
    #[DataProvider('workbenchOnlyContracts')]
    public function a_workbench_contract_is_not_open_to_agents(string $class): void
    {
        $contract = (new ReflectionClass($class))->getAttributes(AsUiContract::class)[0]->newInstance()->metadata();
        self::assertTrue($contract->previewSafe);
        self::assertFalse($contract->agent);
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
