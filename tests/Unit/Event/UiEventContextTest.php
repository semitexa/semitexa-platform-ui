<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Event;

use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventContext;
use stdClass;

final class UiEventContextTest extends TestCase
{
    public function testConstructorAssignsAllReadonlyProperties(): void
    {
        $request = new stdClass();
        $claims = ['handler' => 'EmailChangedHandler', 'aud' => 'platform.field'];

        $context = new UiEventContext(
            eventId: 'evt-1',
            correlationId: 'corr-9',
            semanticEvent: 'change',
            signedClaims: $claims,
            request: $request,
        );

        self::assertSame('evt-1', $context->eventId);
        self::assertSame('corr-9', $context->correlationId);
        self::assertSame('change', $context->semanticEvent);
        self::assertSame($claims, $context->signedClaims);
        self::assertSame($request, $context->request);
    }

    public function testOptionalsDefaultToEmptyArrayAndNull(): void
    {
        $context = new UiEventContext(
            eventId: 'evt-2',
            correlationId: 'corr-2',
            semanticEvent: 'submit',
        );

        self::assertSame([], $context->signedClaims);
        self::assertNull($context->request);
    }

    public function testRequestAcceptsArrayShape(): void
    {
        $context = new UiEventContext(
            eventId: 'evt-3',
            correlationId: 'corr-3',
            semanticEvent: 'click',
            request: ['route' => '/x', 'method' => 'POST'],
        );

        self::assertSame(['route' => '/x', 'method' => 'POST'], $context->request);
    }
}
