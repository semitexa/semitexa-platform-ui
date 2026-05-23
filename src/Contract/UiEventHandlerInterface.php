<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Contract;

use Semitexa\PlatformUi\Domain\Model\Event\UiEventContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiEventResponse;

/**
 * Contract every backend handler invoked by the response-capable UI
 * Interaction dispatcher must satisfy (technical-design.md §7.6.2).
 *
 * Concrete handlers may type the payload more narrowly (`$payload` is
 * declared as `object` here because each handler binds its own DTO via
 * `#[UiOn(payload: …)]`) and may receive the current component State DTO
 * as an extra parameter when the framework can resolve it safely:
 *
 * ```php
 * public function handle(
 *     EmailChangedPayload $payload,
 *     UiEventContext $context,
 *     ContactFormState $state,
 * ): UiEventResponse { ... }
 * ```
 *
 * Handlers MUST return a typed {@see UiEventResponse} — they MUST NOT throw
 * raw framework exceptions back to the dispatcher. Use
 * {@see UiEventResponse::error()} with a stable code for typed failures.
 */
interface UiEventHandlerInterface
{
    public function handle(object $payload, UiEventContext $context): UiEventResponse;
}
