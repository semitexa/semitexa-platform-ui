<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Handler\PayloadHandler;

use Closure;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Request;
use Semitexa\PlatformUi\Application\Payload\Request\UiDispatchPayload;
use Semitexa\PlatformUi\Application\Service\Event\InMemoryUiReplayStore;
use Semitexa\PlatformUi\Application\Service\Event\AllowAllUiInteractionAuthorizer;
use Semitexa\PlatformUi\Application\Service\Event\UiInteractionAuthorizerInterface;
use Semitexa\PlatformUi\Application\Service\Event\UiInteractionDispatcher;
use Semitexa\PlatformUi\Application\Service\Event\UiPatchValidator;
use Semitexa\PlatformUi\Application\Service\Event\UiPayloadFieldGuard;
use Semitexa\PlatformUi\Application\Service\Event\UiReplayStoreInterface;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldRuleRegistryInterface;
use Semitexa\PlatformUi\Domain\Contract\UiEventHandlerInterface;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionBadRequestException;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionException;
use Semitexa\PlatformUi\Domain\Model\Event\UiInteractionResult;
use Throwable;

/**
 * POST /__ui/dispatch — Platform UI HTTP event dispatcher.
 *
 * Reads the raw JSON body, hands it to UiInteractionDispatcher, and
 * serialises the result (or a safe error) as JSON. The endpoint never
 * leaks PHP class/method names, file paths, or stack traces in its
 * response body.
 *
 * Dependency injection (production path):
 *   - UiReplayStoreInterface — bound by default to
 *     CacheBackedUiReplayStore through SatisfiesServiceContract, so
 *     dispatchId replay protection is process-shared via the framework
 *     cache layer.
 *   - UiInteractionAuthorizerInterface — bound by default to
 *     AllowAllUiInteractionAuthorizer through SatisfiesServiceContract.
 *     Apps register their own implementation in a module that "extends"
 *     semitexa/platform-ui to override.
 *
 * Tests that construct the handler manually (no container) leave the
 * two injected properties unset; resolveReplayStore() /
 * resolveAuthorizer() fall back to InMemoryUiReplayStore /
 * AllowAllUiInteractionAuthorizer. The fallback is explicitly worker-
 * local — production paths must NEVER hit it because the container
 * always resolves the SatisfiesServiceContract winner.
 */
#[AsPayloadHandler(payload: UiDispatchPayload::class, resource: ResourceResponse::class)]
final class UiDispatchHandler implements TypedHandlerInterface
{
    #[InjectAsMutable]
    protected Request $request;

    #[InjectAsReadonly]
    protected UiReplayStoreInterface $replayStore;

    #[InjectAsReadonly]
    protected UiInteractionAuthorizerInterface $authorizer;

    #[InjectAsReadonly]
    protected UiFieldRuleRegistryInterface $ruleRegistry;

    /**
     * PSR-11 container used by {@see self::buildHandlerResolver()} to
     * resolve class-level #[HandlesUiEvent] handler FQCNs (e.g. the
     * GridLeadEventHandler service) at dispatch time.
     *
     * Container access is the documented seam for dynamic FQCN-based
     * resolution that #[InjectAsReadonly] can't express on its own —
     * the dispatcher needs to look up an arbitrary service class
     * carried in a verified signed-ctx binding, which is exactly the
     * shape PSR-11 was designed for. Static container access is still
     * forbidden by semitexa.staticContainerAccess; injecting the
     * container as a typed property here is the canonical alternative.
     */
    #[InjectAsReadonly]
    protected ContainerInterface $container;

    public function handle(UiDispatchPayload $_payload, ResourceResponse $resource): ResourceResponse
    {
        $dispatchId = '';
        try {
            $body = $this->decodeBody();
            $ctx = $this->extractCtx($body);
            $dispatchId = $this->extractDispatchId($body);
            $payload = $this->extractPayload($body);

            $result = $this->resolveDispatcher()->dispatch($ctx, $dispatchId, $payload);

            return $this->successResponse($resource, $result, $ctx, $dispatchId);
        } catch (UiInteractionException $e) {
            return $this->errorResponse($resource, $e->httpStatus, $e->reason, $e->getMessage(), $dispatchId);
        } catch (Throwable) {
            return $this->errorResponse($resource, 500, 'internal_error', 'Unexpected dispatcher failure.', $dispatchId);
        }
    }

    private function resolveDispatcher(): UiInteractionDispatcher
    {
        return new UiInteractionDispatcher(
            payloadGuard: new UiPayloadFieldGuard(),
            patchValidator: new UiPatchValidator(),
            replayStore: $this->resolveReplayStore(),
            authorizer: $this->resolveAuthorizer(),
            ruleRegistry: isset($this->ruleRegistry) ? $this->ruleRegistry : null,
            handlerResolver: $this->buildHandlerResolver(),
        );
    }

    /**
     * @return Closure(class-string<UiEventHandlerInterface>): UiEventHandlerInterface|null
     */
    private function buildHandlerResolver(): ?Closure
    {
        if (!isset($this->container)) {
            return null;
        }

        $container = $this->container;
        return static function (string $fqcn) use ($container): UiEventHandlerInterface {
            $service = $container->get($fqcn);
            if (!$service instanceof UiEventHandlerInterface) {
                throw new \RuntimeException(sprintf(
                    'Service %s resolved by the container is not a UiEventHandlerInterface.',
                    $fqcn,
                ));
            }
            return $service;
        };
    }

    private function resolveReplayStore(): UiReplayStoreInterface
    {
        // Production: container has already filled $this->replayStore
        // with the SatisfiesServiceContract winner (CacheBackedUiReplayStore
        // by default). Tests that construct the handler without DI fall
        // through here and get a worker-local store, scoped to this
        // handler instance.
        if (!isset($this->replayStore)) {
            $this->replayStore = new InMemoryUiReplayStore();
        }
        return $this->replayStore;
    }

    private function resolveAuthorizer(): UiInteractionAuthorizerInterface
    {
        if (!isset($this->authorizer)) {
            $this->authorizer = new AllowAllUiInteractionAuthorizer();
        }
        return $this->authorizer;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws UiInteractionBadRequestException
     */
    private function decodeBody(): array
    {
        $raw = $this->request->getContent();
        if ($raw === null || $raw === '') {
            throw new UiInteractionBadRequestException(
                'empty_body',
                'Request body is empty. Expected a JSON object with "ctx", "dispatchId", and optional "payload".',
            );
        }
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new UiInteractionBadRequestException(
                'malformed_json',
                'Request body is not valid JSON.',
            );
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new UiInteractionBadRequestException(
                'body_not_object',
                'Request body must be a JSON object, not a list or a scalar.',
            );
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @throws UiInteractionBadRequestException
     */
    private function extractCtx(array $body): string
    {
        $ctx = $body['ctx'] ?? null;
        if (!is_string($ctx) || $ctx === '') {
            throw new UiInteractionBadRequestException(
                'missing_ctx',
                'Request body must include a non-empty "ctx" field.',
            );
        }
        return $ctx;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @throws UiInteractionBadRequestException
     */
    private function extractDispatchId(array $body): string
    {
        $dispatchId = $body['dispatchId'] ?? null;
        if (!is_string($dispatchId) || $dispatchId === '') {
            throw new UiInteractionBadRequestException(
                'missing_dispatch_id',
                'Request body must include a non-empty "dispatchId" field.',
            );
        }
        return $dispatchId;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     *
     * @throws UiInteractionBadRequestException
     */
    private function extractPayload(array $body): array
    {
        if (!array_key_exists('payload', $body)) {
            return [];
        }
        $payload = $body['payload'];
        if ($payload === null) {
            return [];
        }
        if (!is_array($payload) || array_is_list($payload)) {
            throw new UiInteractionBadRequestException(
                'payload_not_object',
                'The "payload" field must be a JSON object when provided.',
            );
        }
        /** @var array<string, mixed> $payload */
        return $payload;
    }

    private function successResponse(
        ResourceResponse $resource,
        UiInteractionResult $result,
        string $ctx,
        string $dispatchId,
    ): ResourceResponse {
        $claims = \Semitexa\Ssr\Application\Service\UiEvent\SignedContext::verify($ctx) ?? [];

        $patches = [];
        foreach ($result->patches as $patch) {
            $patches[] = $patch->toJsonShape();
        }

        $body = [
            'ok' => true,
            'handled' => true,
            'kind' => $result->kind,
            'dispatchId' => $dispatchId,
            'component' => $this->safeClaim($claims, 'c'),
            'instance' => $this->safeClaim($claims, 'i'),
            'part' => $this->safeClaim($claims, 'p'),
            'event' => $this->safeClaim($claims, 'e'),
            'updates' => $this->safeClaim($claims, 'u'),
            'debug' => $result->debug,
            'patches' => $patches,
        ];

        return $this->jsonResponse($resource, 200, $body);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function safeClaim(array $claims, string $key): ?string
    {
        $value = $claims[$key] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function errorResponse(
        ResourceResponse $resource,
        int $status,
        string $reason,
        string $message,
        string $dispatchId,
    ): ResourceResponse {
        $body = [
            'ok' => false,
            'reason' => $reason,
            'message' => $message,
        ];
        if ($dispatchId !== '') {
            $body['dispatchId'] = $dispatchId;
        }
        return $this->jsonResponse($resource, $status, $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonResponse(ResourceResponse $resource, int $status, array $body): ResourceResponse
    {
        try {
            $json = json_encode(
                $body,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException) {
            $json = '{"ok":false,"reason":"json_encode_failed","message":"Could not encode response."}';
            $status = 500;
        }

        return $resource
            ->setStatusCode($status)
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setContent($json);
    }

    /**
     * Test seam — InjectAsMutable / InjectAsReadonly do not run in unit
     * tests. Production code MUST NOT call these methods; the DI
     * container fills the corresponding properties.
     *
     * Keep narrow: only the three things a unit test cannot stub through
     * DI — the Request, the replay store (so multiple invocations on the
     * same handler share state), and the authorizer (so deny scenarios
     * can be asserted).
     */
    public function withRequest(Request $request): self
    {
        $this->request = $request;
        return $this;
    }

    public function withReplayStore(UiReplayStoreInterface $replayStore): self
    {
        $this->replayStore = $replayStore;
        return $this;
    }

    public function withAuthorizer(UiInteractionAuthorizerInterface $authorizer): self
    {
        $this->authorizer = $authorizer;
        return $this;
    }

    public function withRuleRegistry(UiFieldRuleRegistryInterface $ruleRegistry): self
    {
        $this->ruleRegistry = $ruleRegistry;
        return $this;
    }
}
