<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Event;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventError;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventNotificationInstruction;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventRedirectInstruction;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventResponse;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventResponseStatus;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventValidationResult;

final class UiEventResponseTest extends TestCase
{
    public function testOkFactoryProducesOkStatusAndCarriesCorrelationId(): void
    {
        $response = UiEventResponse::ok('corr-1');

        self::assertSame(UiEventResponseStatus::Ok, $response->status);
        self::assertSame('corr-1', $response->correlationId);
        self::assertNull($response->validation);
        self::assertNull($response->error);
    }

    public function testCommandAcceptedFactoryProducesOkStatus(): void
    {
        $response = UiEventResponse::commandAccepted('corr-2');

        self::assertSame(UiEventResponseStatus::Ok, $response->status);
        self::assertSame('corr-2', $response->correlationId);
    }

    public function testPatchFactoryWithValidErrorsDowngradesToValidationStatus(): void
    {
        $validation = new UiEventValidationResult(['email' => ['Email is required.']]);

        $response = UiEventResponse::patch(
            validation: $validation,
            state: ['email' => ''],
            parts: ['email' => ['hidden' => false]],
        );

        self::assertSame(UiEventResponseStatus::Validation, $response->status);
        self::assertSame($validation, $response->validation);
        self::assertSame(['email' => ''], $response->statePatch);
        self::assertSame(['email' => ['hidden' => false]], $response->partPropsPatch);
    }

    public function testPatchFactoryWithEmptyValidationStaysOk(): void
    {
        $validation = new UiEventValidationResult([]);

        $response = UiEventResponse::patch(validation: $validation);

        self::assertSame(UiEventResponseStatus::Ok, $response->status);
        self::assertTrue($response->validation?->isValid());
    }

    public function testErrorFactoryProducesErrorStatus(): void
    {
        $error = new UiEventError('rate_limited', 'Too many attempts.');

        $response = UiEventResponse::error($error, 'corr-3');

        self::assertSame(UiEventResponseStatus::Error, $response->status);
        self::assertSame($error, $response->error);
        self::assertSame('corr-3', $response->correlationId);
    }

    public function testDirectConstructorCoversAllFields(): void
    {
        $redirect = new UiEventRedirectInstruction('/welcome', replace: true);
        $notification = new UiEventNotificationInstruction(
            message: 'Saved.',
            level: UiEventNotificationInstruction::LEVEL_SUCCESS,
        );

        $response = new UiEventResponse(
            componentPropsPatch: ['title' => 'Hello'],
            rerender: ['parts' => ['list']],
            frontend: ['focus' => 'email'],
            sse: ['subscribe' => 'chan-a'],
            redirect: $redirect,
            notification: $notification,
            correlationId: 'corr-4',
        );

        self::assertSame(['title' => 'Hello'], $response->componentPropsPatch);
        self::assertSame(['parts' => ['list']], $response->rerender);
        self::assertSame(['focus' => 'email'], $response->frontend);
        self::assertSame(['subscribe' => 'chan-a'], $response->sse);
        self::assertSame($redirect, $response->redirect);
        self::assertSame($notification, $response->notification);
        self::assertSame('corr-4', $response->correlationId);
    }

    public function testNotificationRejectsUnknownLevel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new UiEventNotificationInstruction(message: 'x', level: 'bogus');
    }

    public function testConstructorRejectsErrorStatusWithoutError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new UiEventResponse(status: UiEventResponseStatus::Error);
    }

    public function testConstructorRejectsNonErrorStatusCarryingError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new UiEventResponse(
            status: UiEventResponseStatus::Ok,
            error: new UiEventError('x', 'y'),
        );
    }

    public function testConstructorRejectsValidationStatusWithoutErrors(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new UiEventResponse(
            status: UiEventResponseStatus::Validation,
            validation: new UiEventValidationResult([]),
        );
    }

    public function testCommandAcceptedRejectsEmptyCorrelationId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UiEventResponse::commandAccepted('');
    }

    public function testValidationResultHelpers(): void
    {
        $result = new UiEventValidationResult([
            'email' => ['Email is required.', 'Must be a valid address.'],
            'name'  => [],
        ]);

        self::assertFalse($result->isValid());
        self::assertTrue($result->hasField('email'));
        self::assertFalse($result->hasField('name'));
        self::assertFalse($result->hasField('missing'));
        self::assertSame('Email is required.', $result->firstError('email'));
        self::assertNull($result->firstError('missing'));
    }
}
