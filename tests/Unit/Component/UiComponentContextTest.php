<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Component;

use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Domain\Model\Component\UiComponentContext;
use stdClass;

final class UiComponentContextTest extends TestCase
{
    public function testInputReturnsValueByKey(): void
    {
        $context = new UiComponentContext(inputs: ['value' => 'hello', 'count' => 3]);

        self::assertSame('hello', $context->input('value'));
        self::assertSame(3, $context->input('count'));
    }

    public function testInputReturnsNullForMissingKey(): void
    {
        $context = new UiComponentContext();

        self::assertNull($context->input('missing'));
    }

    public function testHasInputDistinguishesMissingFromNullValue(): void
    {
        $context = new UiComponentContext(inputs: ['present' => null]);

        self::assertTrue($context->hasInput('present'));
        self::assertFalse($context->hasInput('absent'));
        self::assertNull($context->input('present'));
    }

    public function testRequestAcceptsObjectArrayOrNull(): void
    {
        $object = new stdClass();

        self::assertNull((new UiComponentContext())->request);
        self::assertSame($object, (new UiComponentContext(request: $object))->request);
        self::assertSame(['route' => '/x'], (new UiComponentContext(request: ['route' => '/x']))->request);
    }
}
