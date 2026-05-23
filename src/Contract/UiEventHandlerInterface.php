<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Contract;

use Semitexa\PlatformUi\Domain\Model\Event\UiEventContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventResponse;

/**
 * Contract every backend handler invoked by the response-capable UI
 * Interaction dispatcher must satisfy (technical-design.md §7.6.2).
 *
 * Implementations MUST keep the exact
 * `handle(object $payload, UiEventContext $context): UiEventResponse`
 * signature — PHP forbids narrowing the parameter type or adding a required
 * parameter on an interface implementation. To work with a typed payload,
 * narrow inside the method:
 *
 * ```php
 * public function handle(object $payload, UiEventContext $context): UiEventResponse
 * {
 *     assert($payload instanceof EmailChangedPayload);
 *     // ...
 * }
 * ```
 *
 * Component-method handlers bound by `#[UiOn(payload: …)]` are dispatched
 * reflectively by the framework — not through this interface — so they may
 * use wider signatures (typed payload, optional component-state parameter).
 *
 * Handlers MUST return a typed {@see UiEventResponse} — they MUST NOT throw
 * raw framework exceptions back to the dispatcher. Use
 * {@see UiEventResponse::error()} with a stable code for typed failures.
 */
interface UiEventHandlerInterface
{
    public function handle(object $payload, UiEventContext $context): UiEventResponse;
}
