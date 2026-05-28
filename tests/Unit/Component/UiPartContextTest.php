<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Component;

use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Domain\Contract\UiPartDataProviderInterface;
use Semitexa\PlatformUi\Domain\Model\Component\UiPartContext;
use stdClass;

final class UiPartContextTest extends TestCase
{
    public function testConstructorAssignsAllReadonlyProperties(): void
    {
        $component = new stdClass();
        $request = ['route' => '/x'];

        $context = new UiPartContext(
            componentInstance: $component,
            partName: 'email',
            request: $request,
        );

        self::assertSame($component, $context->componentInstance);
        self::assertSame('email', $context->partName);
        self::assertSame($request, $context->request);
    }

    public function testRequestDefaultsToNullAndAcceptsObjectOrArray(): void
    {
        $component = new stdClass();

        $contextDefault = new UiPartContext($component, 'label');
        self::assertNull($contextDefault->request);

        $contextObject = new UiPartContext($component, 'label', request: $component);
        self::assertSame($component, $contextObject->request);

        $contextArray = new UiPartContext($component, 'label', request: ['route' => '/x']);
        self::assertSame(['route' => '/x'], $contextArray->request);
    }

    public function testProviderInterfaceConsumesContextAndReturnsArray(): void
    {
        $provider = new class () implements UiPartDataProviderInterface {
            public function provide(UiPartContext $context): array
            {
                return ['part' => $context->partName];
            }
        };

        $result = $provider->provide(new UiPartContext(new stdClass(), 'control'));

        self::assertSame(['part' => 'control'], $result);
    }
}
