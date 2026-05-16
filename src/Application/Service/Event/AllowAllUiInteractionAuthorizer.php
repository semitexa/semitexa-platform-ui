<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\PlatformUi\Domain\Model\Component\UiComponentMetadata;
use Semitexa\PlatformUi\Domain\Model\Component\UiOnMetadata;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionEvent;

/**
 * Default authorizer — allows every verified dispatch.
 *
 * Default binding: registered as the default implementation of
 * UiInteractionAuthorizerInterface via SatisfiesServiceContract. Keeps
 * the existing playground/demo behaviour unchanged while still
 * exercising the authorization seam: every dispatch flows through
 * `authorize()`.
 *
 * Override seam: an application binds its own implementation by
 * declaring a class with #[SatisfiesServiceContract(of:
 * UiInteractionAuthorizerInterface::class)] inside a module that
 * "extends" semitexa/platform-ui. The contract registry's module-order
 * winner picks the descendant module's implementation.
 *
 * Trust note: authorize() receives the resolved component+event
 * identity (already verified by the signed ctx). It MUST NOT read
 * identity from $event->payload or $event->dispatchId — those carry
 * client-supplied data.
 */
#[SatisfiesServiceContract(of: UiInteractionAuthorizerInterface::class)]
final class AllowAllUiInteractionAuthorizer implements UiInteractionAuthorizerInterface
{
    public function authorize(
        UiInteractionEvent $event,
        UiComponentMetadata $component,
        UiOnMetadata $eventMeta,
    ): bool {
        return true;
    }
}
