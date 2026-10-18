<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Refresh;

use Semitexa\PlatformUi\Application\Service\Access\UiPermissions;
use Semitexa\PlatformUi\Application\Service\Component\UiComponentRegistry;
use Semitexa\PlatformUi\Application\Service\Event\UiEventManifestBuilder;
use Semitexa\PlatformUi\Application\Service\Event\UiRefreshAuthorizerInterface;
use Semitexa\PlatformUi\Application\Service\State\UiComponentStates;
use Semitexa\PlatformUi\Application\Service\Island\UiIslandInterface;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionForbiddenException;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionNotFoundException;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionResult;
use Semitexa\PlatformUi\Domain\Model\Event\UiResponsePatch;
use Semitexa\Ssr\Application\Service\Component\ComponentRenderFinisherInterface;
use Semitexa\Ssr\Application\Service\Component\ComponentRootAnnotator;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContextBinding;
use Semitexa\Ssr\Application\Service\UiEvent\UiSseSessionState;

/**
 * Gives an interactive component a way back after its event contexts expire.
 *
 * A component's events are signed for {@see SignedContext::DEFAULT_TTL_SECONDS};
 * a page read for longer than that had its clicks refused and the visitor saw
 * nothing. Its root now carries `data-ui-refresh`: a token naming the
 * component, the instance, the props it was drawn with, the page's KISS
 * session and who it was drawn for. The runtime sends it through HUG like any
 * event; {@see \Semitexa\PlatformUi\Application\Service\Event\UiInteractionDispatcher}
 * answers with the component drawn again — fresh event contexts included — as
 * a morph, and the runtime retries the click once.
 *
 * The token can only redraw what this visitor was already shown: the same
 * component with the same props, for the same session (SignedContext binds it)
 * and the same signed-in subject. It carries no part or event, so it can never
 * stand in for an event context. Islands keep their own live token.
 */
final class UiRefreshFinisher implements ComponentRenderFinisherInterface
{
    /** A page left open all day can still be clicked. */
    public const TOKEN_TTL_SECONDS = 86400;

    public function finish(string $componentName, string $componentClass, string $instanceId, array $props, string $html): string
    {
        // Its own manifest, not one of a child's: a wrapper is not redrawn.
        if (!str_contains($html, 'data-ui-event-manifest="' . htmlspecialchars($instanceId, ENT_QUOTES) . '"')
            || UiComponentRegistry::get($componentName) === null
            || ($componentClass !== '' && is_subclass_of($componentClass, UiIslandInterface::class))
        ) {
            return $html;
        }
        // State the server keeps (#[UiState], or props too large to ride a
        // context) never goes into the page: such a component asks for a
        // reload instead. So does one drawn outside a visitor's session — its
        // token would be bound to no one.
        if (($componentClass !== '' && UiComponentStates::propertiesOf($componentClass) !== []) || !self::boundToASession()) {
            return $html;
        }
        unset($props['instanceId']);
        $signable = $props === [] ? [] : UiEventManifestBuilder::signableProps($props, UiComponentStates::INLINE_LIMIT_BYTES);
        if ($signable === null) {
            // Objects, a list, or too large: it works until its contexts
            // expire, then the runtime asks for a reload.
            return $html;
        }

        $claims = ['rf' => $componentName, 'i' => $instanceId, 'pr' => $signable, 'who' => UiPermissions::subjectId()];
        $pageSession = UiSseSessionState::current();
        if ($pageSession !== null) {
            $claims['sub'] = $pageSession;
        }
        $token = SignedContext::sign($claims, self::TOKEN_TTL_SECONDS);

        return ComponentRootAnnotator::annotateWith($html, ['data-ui-refresh' => $token]);
    }

    /**
     * Answer a refresh token sent through HUG: the component drawn again with
     * the props it was drawn with, as a morph of the same instance. Refused
     * once the visitor is no longer who it was drawn for — a page signed out
     * (or in) since must be reloaded instead.
     *
     * @param array<string, mixed> $claims verified refresh claims
     * @param (\Closure(string, array<string, mixed>): string)|null $render draws in the page's KISS session
     */
    public static function redraw(array $claims, ?\Closure $render, ?UiRefreshAuthorizerInterface $authorizer = null): UiInteractionResult
    {
        $componentName = is_string($claims['rf'] ?? null) ? $claims['rf'] : '';
        $instanceId = is_string($claims['i'] ?? null) ? $claims['i'] : '';
        if ($instanceId === '' || ($claims['who'] ?? null) !== UiPermissions::subjectId()) {
            throw new UiInteractionForbiddenException(
                'refresh_subject_changed',
                'The component was drawn for another visitor; reload the page.',
            );
        }
        $metadata = UiComponentRegistry::get($componentName);
        if ($render === null || $metadata === null) {
            throw new UiInteractionNotFoundException(
                'unknown_component',
                'Signed component identity does not match any registered Platform UI component.',
            );
        }
        /** @var array<string, mixed> $props signed by the finisher as a map */
        $props = is_array($claims['pr'] ?? null) ? $claims['pr'] : [];
        if ($authorizer !== null && !$authorizer->authorizeRefresh($metadata, $instanceId, $props)) {
            throw new UiInteractionForbiddenException(
                'refresh_forbidden',
                'Authorization policy denied drawing this component again; reload the page.',
            );
        }

        return UiInteractionResult::patch([
            UiResponsePatch::morph($instanceId, $render($componentName, ['instanceId' => $instanceId] + $props)),
        ]);
    }

    private static function boundToASession(): bool
    {
        $binding = SignedContextBinding::current();

        return $binding !== null && $binding['s'] !== '';
    }
}
