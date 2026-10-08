<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Payload\Request;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Core\Attribute\LiveFilterParam;
use Semitexa\Core\Attribute\SseGateModel;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Core\Request;
use Semitexa\Core\Resource\JsonResourceResponse;
use Semitexa\Core\Resource\RenderProfile;
use Semitexa\PlatformUi\Application\Service\Island\UiIslandInterface;
use Semitexa\Ssr\Application\Service\Component\ComponentRegistry;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;
use Semitexa\Ssr\Domain\Contract\DynamicallyScopedFeedInterface;
use Semitexa\Ssr\Domain\Contract\SseFeedPayloadInterface;

/**
 * The live feed of one island (UiIslandInterface): the island runtime
 * subscribes it through HUG by name with the island's signed token, and every
 * write to what the island watches re-runs it — the component drawn again, for
 * this visitor, with the props it was drawn with. A plain GET answers once.
 *
 * The token is the only input: it names the component, the instance and the
 * props, signed when the page was drawn, so a page cannot ask for anything
 * else. A token that does not verify names no island.
 */
#[AsProtectedPayload(
    path: '/platform/island/feed',
    name: 'platform-ui.island.feed',
    methods: ['GET'],
    responseWith: JsonResourceResponse::class,
    renderProfile: RenderProfile::Json,
    transport: TransportType::Sse,
    sseGateModel: SseGateModel::BearerSession,
)]
final class IslandFeedPayload implements SseFeedPayloadInterface, DynamicallyScopedFeedInterface
{
    #[LiveFilterParam]
    private string $island = '';

    /**
     * Set by the page's first subscribe: the page already shows this render,
     * so that first frame need not draw it again. Taken once — the
     * subscription's later re-runs draw.
     */
    #[LiveFilterParam]
    private bool $drawn = false;

    /** @var array{component: string, instance: string, props: array<array-key, mixed>}|null */
    private ?array $claims = null;

    private ?string $streamId = null;

    private ?Request $httpRequest = null;

    public function setHttpRequest(Request $request): void
    {
        $this->httpRequest = $request;
    }

    public function getHttpRequest(): ?Request
    {
        return $this->httpRequest;
    }

    public function setStreamId(?string $streamId): void
    {
        $streamId = trim((string) $streamId);
        $this->streamId = $streamId === '' ? null : $streamId;
    }

    public function getStreamId(): ?string
    {
        return $this->streamId;
    }

    public function setIsland(string $island): void
    {
        $this->island = trim($island);
        $claims = $this->island === '' ? null : SignedContext::verify($this->island);
        $this->claims = is_array($claims) && is_string($claims['isl'] ?? null) && is_string($claims['i'] ?? null)
            ? ['component' => $claims['isl'], 'instance' => $claims['i'], 'props' => is_array($claims['pr'] ?? null) ? $claims['pr'] : []]
            : null;
    }

    public function setDrawn(string $drawn): void
    {
        $this->drawn = $drawn === '1';
    }

    /** Whether the page already shows the island as it is now — true once. */
    public function takeDrawn(): bool
    {
        $drawn = $this->drawn;
        $this->drawn = false;

        return $drawn;
    }

    /** @return array{component: string, instance: string, props: array<array-key, mixed>}|null the island the token names */
    public function island(): ?array
    {
        return $this->claims;
    }

    /** @return list<string> */
    public function dynamicWatchScopes(): array
    {
        if ($this->claims === null) {
            return [];
        }
        $class = ComponentRegistry::get($this->claims['component'])['class'] ?? null;
        if (!is_string($class) || !is_subclass_of($class, UiIslandInterface::class)) {
            return [];
        }

        return array_values(array_filter(
            (new $class())->watches($this->claims['props']),
            static fn (mixed $scope): bool => is_string($scope) && trim($scope) !== '',
        ));
    }

    /** @return array<string, mixed> */
    public function toViewParams(): array
    {
        return ['island' => $this->island, 'drawn' => '', 'streamId' => $this->streamId ?? ''];
    }
}
