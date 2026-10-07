<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

use Semitexa\PlatformUi\Domain\Exception\UiInteractionUnprocessableException;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventResponse;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventResponseStatus;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionResult;
use Semitexa\PlatformUi\Domain\Model\Event\UiResponsePatch;

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
 * envelope: HUG's response carries `debug` verbatim under the top-level
 * `"debug"` key, and the frontend reads the same
 * fields it would read from a canonical UiEventResponse JSON, just nested
 * one level deeper.
 *
 * Error responses throw {@see UiInteractionUnprocessableException} so the
 * dispatcher's existing exception-mapping path produces the documented
 * 422 envelope without any new code in the dispatch handler.
 *
 * The instructions the one effect vocabulary can express become effects
 * ({@see UiResponsePatch}): a redirect, a notification (toast), and a
 * re-render (`rerender` / `componentPropsPatch` → `rerender` with those
 * props, which the dispatcher turns into a morph). The rest stays in `debug`.
 */
final class UiInteractionDispatchAdapter
{
    public function toInteractionResult(UiEventResponse $response, string $instanceId = ''): UiInteractionResult
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

        $effects = [];
        if ($instanceId !== '') {
            if ($response->rerender !== [] || $response->componentPropsPatch !== []) {
                $effects[] = UiResponsePatch::rerender($instanceId, $response->componentPropsPatch);
            }
            if ($response->notification !== null) {
                $effects[] = UiResponsePatch::toast(
                    $instanceId,
                    $response->notification->message,
                    $response->notification->level,
                    $response->notification->title,
                );
            }
            if ($response->redirect !== null) {
                $effects[] = UiResponsePatch::redirect($instanceId, $response->redirect->url, $response->redirect->replace);
            }
        }

        return $effects === [] ? UiInteractionResult::ack($debug) : UiInteractionResult::patch($effects, $debug);
    }
}
