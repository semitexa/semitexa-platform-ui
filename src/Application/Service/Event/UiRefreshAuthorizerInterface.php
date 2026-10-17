<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Event;

use Semitexa\PlatformUi\Domain\Model\Component\UiComponentMetadata;

/**
 * Opt-in counterpart of {@see UiInteractionAuthorizerInterface} for a
 * component drawn again from its refresh token (a page whose event contexts
 * expired). A bound authorizer that also implements this is asked before the
 * redraw; one that does not leaves the redraw to the token's own checks — the
 * same session, tenant and signed-in subject it was drawn for.
 */
interface UiRefreshAuthorizerInterface
{
    /** @param array<string, mixed> $props the props the component was drawn with */
    public function authorizeRefresh(UiComponentMetadata $component, string $instanceId, array $props): bool;
}
