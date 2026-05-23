<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Event;

use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Contract\UiEventHandlerInterface;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventResponse;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventResponseStatus;
use stdClass;

final class UiEventHandlerInterfaceTest extends TestCase
{
    public function testInterfaceAcceptsAnyPayloadObjectAndReturnsUiEventResponse(): void
    {
        $handler = new class () implements UiEventHandlerInterface {
            public function handle(object $payload, UiEventContext $context): UiEventResponse
            {
                return UiEventResponse::commandAccepted($context->correlationId);
            }
        };

        $payload = new stdClass();
        $payload->intent = 'save';

        $context = new UiEventContext(
            eventId: 'evt-1',
            correlationId: 'corr-99',
            semanticEvent: 'submit',
        );

        $response = $handler->handle($payload, $context);

        self::assertSame(UiEventResponseStatus::Ok, $response->status);
        self::assertSame('corr-99', $response->correlationId);
    }
}
