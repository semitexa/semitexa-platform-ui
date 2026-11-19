<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\PlatformUi\Application\Service\Event\UiInteractionDispatchAdapter;
use Semitexa\PlatformUi\Application\Service\Event\PlatformUiResponseDispatcher;
use Semitexa\Ssr\Application\Service\UiEvent\UiResponseDispatchResult;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionUnprocessableException;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventError;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventResponse;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventResponseStatus;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionResult;

final class UiInteractionDispatchAdapterTest extends TestCase
{
    private UiInteractionDispatchAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new UiInteractionDispatchAdapter();
    }

    #[Test]
    public function ok_maps_to_ack_with_status_only(): void
    {
        $result = $this->adapter->toInteractionResult(UiEventResponse::ok());

        self::assertSame(UiInteractionResult::KIND_ACK, $result->kind);
        self::assertSame([], $result->patches);
        self::assertSame(['status' => 'ok'], $result->debug);
    }

    #[Test]
    public function ok_with_correlation_id_carries_it_through_debug(): void
    {
        $result = $this->adapter->toInteractionResult(UiEventResponse::ok('corr-1234'));

        self::assertSame('ok', $result->debug['status']);
        self::assertSame('corr-1234', $result->debug['correlationId']);
    }

    #[Test]
    public function command_accepted_carries_correlation_id_through_debug(): void
    {
        $result = $this->adapter->toInteractionResult(UiEventResponse::commandAccepted('cmd-abc'));

        self::assertSame(UiInteractionResult::KIND_ACK, $result->kind);
        self::assertSame('cmd-abc', $result->debug['correlationId']);
    }

    #[Test]
    public function accepted_with_frontend_carries_both_through_debug(): void
    {
        $result = $this->adapter->toInteractionResult(UiEventResponse::accepted(
            correlationId: 'acc-xyz',
            frontend: ['subscribe' => ['channel' => 'sse_grid01']],
        ));

        self::assertSame(UiInteractionResult::KIND_ACK, $result->kind);
        self::assertSame('acc-xyz', $result->debug['correlationId']);
        self::assertSame(['subscribe' => ['channel' => 'sse_grid01']], $result->debug['frontend']);
    }

    #[Test]
    public function patch_with_state_carries_state_through_debug(): void
    {
        $result = $this->adapter->toInteractionResult(UiEventResponse::patch(
            state: ['rows' => [['id' => 1], ['id' => 2]], 'cursor' => 'c2'],
        ));

        self::assertSame(UiInteractionResult::KIND_ACK, $result->kind);
        self::assertSame([
            'rows' => [['id' => 1], ['id' => 2]],
            'cursor' => 'c2',
        ], $result->debug['state']);
    }

    #[Test]
    public function patch_with_parts_carries_parts_through_debug(): void
    {
        $result = $this->adapter->toInteractionResult(UiEventResponse::patch(
            parts: [
                'input' => ['value' => 'updated'],
                'badge' => ['text' => 'saved'],
            ],
        ));

        self::assertSame(UiInteractionResult::KIND_ACK, $result->kind);
        self::assertSame([
            'input' => ['value' => 'updated'],
            'badge' => ['text' => 'saved'],
        ], $result->debug['parts']);
    }

    #[Test]
    public function omits_empty_state_parts_and_frontend_from_debug(): void
    {
        $result = $this->adapter->toInteractionResult(UiEventResponse::ok());

        self::assertArrayNotHasKey('state', $result->debug);
        self::assertArrayNotHasKey('parts', $result->debug);
        self::assertArrayNotHasKey('componentProps', $result->debug);
        self::assertArrayNotHasKey('frontend', $result->debug);
        self::assertArrayNotHasKey('sse', $result->debug);
        self::assertArrayNotHasKey('rerender', $result->debug);
        self::assertArrayNotHasKey('correlationId', $result->debug);
    }

    #[Test]
    public function direct_constructor_with_component_props_and_sse_is_carried_through(): void
    {
        $response = new UiEventResponse(
            status: UiEventResponseStatus::Ok,
            componentPropsPatch: ['title' => 'New title'],
            sse: ['session' => ['id' => 'sse_grid_001']],
            rerender: ['parts' => ['rows']],
        );

        $result = $this->adapter->toInteractionResult($response);

        self::assertSame(['title' => 'New title'], $result->debug['componentProps']);
        self::assertSame(['session' => ['id' => 'sse_grid_001']], $result->debug['sse']);
        self::assertSame(['parts' => ['rows']], $result->debug['rerender']);
    }

    #[Test]
    public function error_response_throws_unprocessable_with_code_and_message(): void
    {
        $response = UiEventResponse::error(new UiEventError(
            code: 'lead_filter_invalid',
            message: 'q must be at least 2 characters.',
        ));

        $this->expectException(UiInteractionUnprocessableException::class);
        $this->expectExceptionMessage('q must be at least 2 characters.');

        $this->adapter->toInteractionResult($response);
    }

    #[Test]
    public function error_exception_carries_the_response_error_code(): void
    {
        $response = UiEventResponse::error(new UiEventError(
            code: 'grid_dp_forbidden',
            message: 'Caller is not authorised for this data provider.',
        ));

        try {
            $this->adapter->toInteractionResult($response);
            self::fail('Expected UiInteractionUnprocessableException to be thrown.');
        } catch (UiInteractionUnprocessableException $e) {
            self::assertSame('grid_dp_forbidden', $e->reason);
            self::assertSame(422, $e->httpStatus);
        }
    }

    #[Test]
    public function error_details_reach_the_wire_and_an_error_without_them_adds_nothing(): void
    {
        // The details are what the client acts on: a collaborative save that
        // conflicts is told which draft and version to retry on.
        $withDetails = UiEventResponse::error(new UiEventError('form_draft_version_conflict', 'Changed.', ['currentEpoch' => 'fcd_1', 'currentVersion' => 3]));
        $translate = new \ReflectionMethod(PlatformUiResponseDispatcher::class, 'translateFailure');

        try {
            $this->adapter->toInteractionResult($withDetails);
            self::fail('Expected UiInteractionUnprocessableException to be thrown.');
        } catch (UiInteractionUnprocessableException $e) {
            self::assertSame(['currentEpoch' => 'fcd_1', 'currentVersion' => 3], $e->details);
            $result = $translate->invoke(new PlatformUiResponseDispatcher(), $e, 'evt-1');
            self::assertInstanceOf(UiResponseDispatchResult::class, $result);
            self::assertSame(['dispatchId' => 'evt-1', 'details' => ['currentEpoch' => 'fcd_1', 'currentVersion' => 3]], $result->body);
        }

        $plain = $translate->invoke(new PlatformUiResponseDispatcher(), new UiInteractionUnprocessableException('nope', 'No.'), 'evt-2');
        self::assertInstanceOf(UiResponseDispatchResult::class, $plain);
        self::assertSame(['dispatchId' => 'evt-2'], $plain->body);
    }
}
