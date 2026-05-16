<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

use Semitexa\PlatformUi\Domain\Model\Component\UiComponentMetadata;
use Semitexa\PlatformUi\Domain\Model\Component\UiOnMetadata;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionEvent;

/**
 * Policy seam: decide whether a verified UiInteractionEvent should be
 * dispatched to its declared #[UiOn] handler.
 *
 * Runs after:
 *   - SignedContext::verify()      passed;
 *   - UiComponentRegistry lookup   passed;
 *   - UiOn metadata resolution     passed;
 *   - updates-path compatibility   passed;
 *   - replay-guard claim           succeeded.
 *
 * Runs before:
 *   - the declared handler method is invoked;
 *   - any response patches are validated or serialised.
 *
 * Contract:
 *   - return `true` to allow dispatch;
 *   - return `false` to deny.
 *
 * Implementations should be pure and side-effect-free in this slice —
 * a `false` return must NOT mutate request-scoped state or write logs
 * containing user-supplied content. The dispatcher converts a `false`
 * return into a 403 with a safe reason token.
 *
 * The default `AllowAllUiInteractionAuthorizer` keeps existing playground
 * behaviour. Apps that need a real policy bind their own implementation
 * to this interface in the container.
 */
interface UiInteractionAuthorizerInterface
{
    public function authorize(
        UiInteractionEvent $event,
        UiComponentMetadata $component,
        UiOnMetadata $eventMeta,
    ): bool;
}
