<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Support;

use Semitexa\PlatformUi\Application\Service\Event\UiInteractionAuthorizerInterface;
use Semitexa\PlatformUi\Domain\Model\Component\UiComponentMetadata;
use Semitexa\PlatformUi\Domain\Model\Component\UiExternalHandlerMetadata;
use Semitexa\PlatformUi\Domain\Model\Component\UiOnMetadata;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionEvent;

/**
 * An application's authorizer whose answer changes while a page is open:
 * everything is allowed until a test revokes a `part.event` on a scope (the
 * signed `cfg.scope` the event carries), as an app does when somebody's
 * access to a record ends.
 */
final class RevocableAuthorizer implements UiInteractionAuthorizerInterface
{
    /** @var array<string, true> "<scope> <part>.<event>" */
    private array $revoked = [];

    /** @var list<string> every "<part>.<event>" asked about, in order */
    public array $asked = [];

    public function revoke(string $scope, string $semanticEvent): void
    {
        $this->revoked[$scope . ' ' . $semanticEvent] = true;
    }

    public function authorize(UiInteractionEvent $event, UiComponentMetadata $component, UiOnMetadata $eventMeta): bool
    {
        return $this->allows($event);
    }

    public function authorizeExternal(UiInteractionEvent $event, UiComponentMetadata $component, UiExternalHandlerMetadata $externalMeta): bool
    {
        return $this->allows($event);
    }

    private function allows(UiInteractionEvent $event): bool
    {
        $semanticEvent = $event->partName . '.' . $event->eventName;
        $this->asked[] = $semanticEvent;
        $scope = $event->config['scope'] ?? '';

        return !isset($this->revoked[(is_string($scope) ? $scope : '') . ' ' . $semanticEvent]);
    }
}
