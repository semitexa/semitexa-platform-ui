<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

use Semitexa\PlatformUi\Domain\Exception\UiInteractionUnprocessableException;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventResponse;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventResponseStatus;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionResult;

/**
 * Bridges canonical {@see UiEventResponse} handler returns into the
 * legacy {@see UiInteractionResult} dispatcher transport.
 *
 * Today's UiInteractionResult exposes a narrow patch op set
 * (`setText` / `setValue` / `setAttribute`) — too narrow to express the
 * richer cases UiEventResponse covers (state patches, part props,
 * component props, frontend instructions, SSE subscriptions, redirects).
 * The adapter folds the richer fields into the result's `debug` map so
 * they flow through the existing JSON wire format without changing the
 * envelope: `UiDispatchHandler::successResponse()` emits `debug` verbatim
 * under the top-level `"debug"` key, and the frontend reads the same
 * fields it would read from a canonical UiEventResponse JSON, just nested
 * one level deeper.
 *
 * Error responses throw {@see UiInteractionUnprocessableException} so the
 * dispatcher's existing exception-mapping path produces the documented
 * 422 envelope without any new code in the dispatch handler.
 *
 * Typed instruction objects (redirect / notification / validation) are
 * not encoded yet — Phase 5 grid flow uses `commandAccepted` +
 * `statePatch` only. When the first consumer needs a typed instruction,
 * extend this adapter to read its primitive fields rather than dumping
 * the object reference.
 */
final class UiInteractionDispatchAdapter
{
    public function toInteractionResult(UiEventResponse $response): UiInteractionResult
    {
        if ($response->status === UiEventResponseStatus::Error) {
            $error = $response->error;
            // The UiEventResponse constructor guarantees $error is non-null
            // when status === Error, so this assertion documents the invariant
            // rather than guards against runtime drift.
            assert($error !== null);
            throw new UiInteractionUnprocessableException(
                $error->code,
                $error->message,
            );
        }

        $debug = [
            'status' => $response->status->value,
        ];

        if ($response->correlationId !== null) {
            $debug['correlationId'] = $response->correlationId;
        }
        if ($response->statePatch !== []) {
            $debug['state'] = $response->statePatch;
        }
        if ($response->partPropsPatch !== []) {
            $debug['parts'] = $response->partPropsPatch;
        }
        if ($response->componentPropsPatch !== []) {
            $debug['componentProps'] = $response->componentPropsPatch;
        }
        if ($response->rerender !== []) {
            $debug['rerender'] = $response->rerender;
        }
        if ($response->frontend !== []) {
            $debug['frontend'] = $response->frontend;
        }
        if ($response->sse !== []) {
            $debug['sse'] = $response->sse;
        }

        return UiInteractionResult::ack($debug);
    }
}
