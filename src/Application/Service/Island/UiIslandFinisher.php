<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Island;

use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\PlatformUi\Application\Service\Access\UiPermissions;
use Semitexa\PlatformUi\Application\Service\Event\UiPageSseSession;
use Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore;
use Semitexa\Ssr\Application\Service\Component\ComponentRenderFinisherInterface;
use Semitexa\Ssr\Application\Service\Component\ComponentRootAnnotator;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContextBinding;

/**
 * Marks an island ({@see UiIslandInterface}) rendered for a signed-in visitor:
 * its root carries `data-ui-island`, a signed token naming the component, the
 * instance and the props it was drawn with — what the island feed re-renders,
 * so a page cannot ask for another component or other props. The page gets
 * its KISS session (announced once, into <head>) and the island runtime,
 * which subscribes the feed.
 */
final class UiIslandFinisher implements ComponentRenderFinisherInterface
{
    /** How long an island token is good for: a page left open all day stays live. */
    public const TOKEN_TTL_SECONDS = 43200;

    /** Props larger than this are not signed into a token: the island renders, but not live. */
    private const MAX_PROPS_BYTES = 8192;

    public function finish(string $componentName, string $componentClass, string $instanceId, array $props, string $html): string
    {
        if ($componentClass === '' || !is_subclass_of($componentClass, UiIslandInterface::class)) {
            return $html;
        }
        if (SignedContextBinding::current() === null || !UiPermissions::signedIn()) {
            return $html;
        }
        unset($props['instanceId']);
        $encoded = json_encode($props, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || strlen($encoded) > self::MAX_PROPS_BYTES) {
            StaticLoggerBridge::warning('platform-ui', 'Island not live: its props cannot be signed', [
                'component' => $componentName,
                'bytes' => is_string($encoded) ? strlen($encoded) : null,
            ]);

            return $html;
        }

        $token = SignedContext::sign(['isl' => $componentName, 'i' => $instanceId, 'pr' => $props], self::TOKEN_TTL_SECONDS);
        AssetCollectorStore::get()->require('platform-ui:js:island-runtime');

        // The page's KISS session goes to <head> once, not in front of every
        // island: two islands used to leave two copies of the same meta pair.
        UiPageSseSession::announce();

        return ComponentRootAnnotator::annotateWith($html, [
            'data-ui-component' => $componentName,
            'data-ui-component-instance-id' => $instanceId,
            'data-ui-island' => $token,
        ]);
    }
}
