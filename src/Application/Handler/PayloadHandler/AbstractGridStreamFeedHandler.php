<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Discovery\RouteRegistry;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Pipeline\ReRun\ReRunContext;
use Semitexa\Core\Server\SwooleBootstrap;
use Semitexa\PlatformUi\Attribute\GridFeed;
use Semitexa\PlatformUi\Domain\Contract\GridStreamPayloadInterface;
use Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer;
use Semitexa\Ssr\Domain\Model\SubscriptionRecord;

/**
 * All-Grids Declarative · the package-level held-open grid-stream FEED handler.
 *
 * This is the generalized form of the leads grid's once-bespoke
 * `LeadGridStreamHandler`: the held-open-SSE serving machinery — server-minted
 * stream id + `ui.stream.id` first frame, the held-open serve handed to
 * {@see AsyncResourceSseServer::serveResourceStream()}, the
 * `X-Semitexa-Stream-Rehydrate` re-hydrate intake via
 * {@see AsyncResourceSseServer::submitViewChange()}, the SSE-vs-JSON content
 * negotiation, and the JSON degrade — lives here ONCE, driven by a grid's
 * `#[GridFeed]` declaration. A concrete grid handler supplies only the
 * grid-specific seams (its row envelope, its grid id / route; the watched
 * live-on-events scopes are DECLARED on the grid via `#[GridFeed(liveOn:)]`,
 * not coded here) and binds the route via its own `#[AsPayloadHandler]` + payload
 * `#[AsPublicPayload]`. A second grid is then a payload + a thin subclass + a
 * row source — no copy of this serving code.
 *
 * ONE PIPELINE — the wire transport (SSE frame vs JSON body) is the ONLY fork
 * (the C3 invariant: same DTO ⇒ same rows; mode does not fork the pipeline).
 * Per the one-URL design this is app/package-side ONLY: `/grid-stream` is not
 * raw-intercepted, so reusing `submitViewChange` + the framework's mint +
 * `ui.stream.id` + re-hydrate intake needs ZERO core/ssr serving-path edits.
 *
 * Filters / paging / sort are read ONLY from the grid's typed payload (via
 * {@see buildEnvelope()}); the only request access here is transport metadata
 * (the `Accept` header + the `X-Semitexa-Stream-Rehydrate` intent header).
 */
abstract class AbstractGridStreamFeedHandler
{
    /** SSE frame event names for a grid data / error payload. */
    public const SSE_EVENT_DATA = 'ui.grid.data';
    public const SSE_EVENT_ERROR = 'ui.grid.error';

    /**
     * One-URL Grid — the re-hydrate intake header. A PURE intent flag: its
     * presence routes the POST to the view-change branch; the stream id + new
     * view params ride the typed payload, never this header. Read for routing
     * intent ONLY, exactly like `Accept` — NEVER as a filter.
     */
    public const REHYDRATE_HEADER = 'X-Semitexa-Stream-Rehydrate';

    #[InjectAsReadonly]
    protected RouteRegistry $routeRegistry;

    /**
     * Needed to resolve the re-run route WITH its handler binding.
     * {@see RouteRegistry::findRouteTyped()} only populates a route's
     * `handlers` when a HandlerRegistry is supplied; without it the re-run
     * pipeline runs NO handler and the held-open stream delivers an empty
     * frame. Sourced from AttributeDiscovery (its canonical holder).
     */
    #[InjectAsReadonly]
    protected AttributeDiscovery $attributeDiscovery;

    /**
     * Used to find the grid component whose `#[GridFeed]` declares THIS feed
     * route, so the held-open subscription's watched scopes are sourced from
     * the declared {@see GridFeed::$liveOn} (live-on-events Phase 2) rather than
     * a hardcoded per-handler constant. {@see resolveWatchedScopeKeys()}.
     */
    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    /**
     * Route → resolved live-on scope keys, memoized per worker. The
     * declaration→subscription resolution is classmap-stable for the life of a
     * worker, so it is reflected once per feed route, not once per connect.
     *
     * @var array<string, list<string>>
     */
    private static array $watchedScopeKeyCache = [];

    // ---- Grid-specific seams a concrete handler MUST supply ----------------

    /**
     * Build the grid's row envelope from its typed payload — the single
     * row-resolution path, shared by both response modes. Returns a success
     * envelope (`{ok:true, gridId, rows, pagination, filters}`, e.g. via
     * {@see \Semitexa\PlatformUi\Domain\Model\Grid\UiGridDataResponse::success()})
     * or an error envelope (`{ok:false, reason, message}`).
     *
     * @return array<string, mixed>
     */
    abstract protected function buildEnvelope(GridStreamPayloadInterface $payload): array;

    /** This feed endpoint's own route path — used to rebuild the re-run chain. */
    abstract protected function gridStreamRoutePath(): string;

    /** This feed endpoint's own route method (the GET held-open verb). */
    abstract protected function gridStreamRouteMethod(): string;

    // ---- The shared pipeline -----------------------------------------------

    /**
     * The unified intake. When the re-hydrate header is present this request is
     * a VIEW-CHANGE command on the open stream (enqueue + ACK only, never
     * rows); otherwise resolve the envelope once (mode-agnostic) and emit it on
     * the ONLY fork — the response transport. A concrete handler's
     * `handle(ConcretePayload, ResourceResponse)` delegates here.
     */
    protected function serve(GridStreamPayloadInterface $payload, ResourceResponse $resource): ResourceResponse
    {
        if ($this->isReHydrateRequest($payload)) {
            return $this->acceptViewChange($payload, $resource);
        }

        $envelope = $this->buildEnvelope($payload);
        $success = ($envelope['ok'] ?? false) === true;
        // Errors here are all client errors (invalid search / invalid cursor).
        $status = $success ? 200 : 400;

        return $this->respond($payload, $resource, $status, $envelope, $success);
    }

    /**
     * Is this the re-hydrate (view-change) intake? True iff the
     * `X-Semitexa-Stream-Rehydrate` intent header is present and non-empty.
     * Header read for routing intent ONLY (transport metadata, like `Accept`).
     */
    protected function isReHydrateRequest(GridStreamPayloadInterface $payload): bool
    {
        $request = $payload->getHttpRequest();
        if ($request === null) {
            return false;
        }
        $flag = $request->getHeader(self::REHYDRATE_HEADER);

        return is_string($flag) && trim($flag) !== '';
    }

    /**
     * The absorbed view-change command body: enqueue the view change onto the
     * held stream and ACK ONLY (never rows). Reuses
     * {@see AsyncResourceSseServer::submitViewChange()} VERBATIM — it validates
     * the stream-id shape, coalesces (latest-view-wins), and enqueues a
     * `{__ctrl:viewchange}` control onto that stream's session-addressed queue;
     * the owning worker re-runs the grid chain and pushes the fresh rows on the
     * SAME open fd. The response carries NO rows — only `{ok, accepted}`, 202
     * (queued) / 400 (invalid stream id).
     */
    protected function acceptViewChange(GridStreamPayloadInterface $payload, ResourceResponse $resource): ResourceResponse
    {
        $streamId = (string) $payload->getStreamId();

        $accepted = AsyncResourceSseServer::submitViewChange($streamId, $payload->toViewParams());

        return $resource
            ->setStatusCode($accepted ? 202 : 400)
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setContent((string) json_encode(
                $accepted
                    ? ['ok' => true, 'accepted' => true]
                    : ['ok' => false, 'accepted' => false, 'reason' => 'invalid_session'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));
    }

    /**
     * The ONLY place mode forks — pure response transport. The grid prefers SSE
     * (`Accept: text/event-stream`). When SSE is available (a live Swoole
     * socket is held) the SAME `$envelope` is written as a held-open
     * `text/event-stream`; otherwise it degrades to a classic JSON body.
     *
     * @param array<string, mixed> $envelope
     */
    protected function respond(
        GridStreamPayloadInterface $payload,
        ResourceResponse $resource,
        int $status,
        array $envelope,
        bool $success,
    ): ResourceResponse {
        if ($this->prefersSse($payload)) {
            // RE-RUN TICK: a `{__ctrl:rerun}` re-ran this chain (the live fd is
            // already held + being streamed on) — produce the framed BODY as
            // JSON; the held-open loop writes it as the fresh frame.
            if (AsyncResourceSseServer::isReRunInProgress()) {
                return $this->jsonResponse($resource, $status, self::frameData($envelope, $success));
            }

            // INITIAL CONNECT: hand the live socket to the held-open serve.
            $served = $this->serveHeldOpen($payload, $resource, $envelope, $success);
            if ($served !== null) {
                return $served;
            }
            // SSE preferred but no live socket → fall through to JSON degrade.
        }

        return $this->jsonResponse($resource, $status, $envelope);
    }

    /**
     * Wrap the envelope in its typed SSE frame body: prepend the `_type` the
     * server's frame chokepoint promotes to an `event:` line (`ui.grid.data` /
     * `ui.grid.error`). Used for BOTH the initial frame and the re-run body, so
     * the two are byte-identical.
     *
     * @param array<string, mixed> $envelope
     * @return array<string, mixed>
     */
    public static function frameData(array $envelope, bool $success): array
    {
        return ['_type' => $success ? self::SSE_EVENT_DATA : self::SSE_EVENT_ERROR] + $envelope;
    }

    /**
     * Convert the one-shot serve into a HELD-OPEN stream serviced by the
     * framework drain loop. Grabs the raw Swoole socket (the same way
     * {@see \Semitexa\Ssr\Application\Handler\PayloadHandler\SseKissHandler}
     * does), then hands it to {@see AsyncResourceSseServer::serveResourceStream()}
     * with the consumer-half inputs. Returns the already-sent resource on
     * success, or null when no live socket is available so the caller degrades
     * to JSON.
     *
     * The server-minted id is the SOLE stream coordinate: the framework mints
     * it at connect, announces it as the first `ui.stream.id` event for the
     * client to adopt, AND keys the held-open stream by it — announced ==
     * addressing key, unconditionally. A client-sent `?stream_id=` on the GET
     * connect is ignored. (The POST re-hydrate command still carries the
     * ADOPTED server id in its body — that IS this very id.)
     *
     * @param array<string, mixed> $envelope
     */
    private function serveHeldOpen(
        GridStreamPayloadInterface $payload,
        ResourceResponse $resource,
        array $envelope,
        bool $success,
    ): ?ResourceResponse {
        if (!class_exists(SwooleBootstrap::class) || !class_exists(AsyncResourceSseServer::class)) {
            return null;
        }
        $context = SwooleBootstrap::getCurrentSwooleRequestResponse();
        if ($context === null || ($context[1] ?? null) === null || ($context[2] ?? null) === null) {
            return null;
        }
        [$swooleRequest, $swooleResponse, $server] = $context;

        $serverStreamId = AsyncResourceSseServer::mintStreamId();
        $sessionId = $serverStreamId;
        $reRunContext = $this->buildReRunContext($payload, $sessionId);
        // The record + context share streaming_id (= sessionId); if the route
        // can't be resolved for the re-run chain we still hold the stream open,
        // just without a live re-run source (passed as null/null).
        $record = $reRunContext === null ? null : $this->buildSubscriptionRecord($sessionId);

        AsyncResourceSseServer::setServer($server);
        AsyncResourceSseServer::serveResourceStream(
            $swooleRequest,
            $swooleResponse,
            $sessionId,
            self::frameData($envelope, $success),
            $record,
            $reRunContext,
            $serverStreamId,
        );

        $resource->setContent('');
        $resource->markAsAlreadySent();
        return $resource;
    }

    /**
     * Build the worker-local re-run state ({@see ReRunContext}) the coordinator
     * stores under streaming_id, re-run auth-first on each mutation. The cached
     * DTO supplies the unchanged request SHAPE only — identity is re-resolved
     * each tick. Returns null when this endpoint's route cannot be resolved
     * (then the stream holds open without a live re-run source).
     */
    private function buildReRunContext(GridStreamPayloadInterface $payload, string $sessionId): ?ReRunContext
    {
        $route = $this->routeRegistry->findRouteTyped(
            $this->gridStreamRoutePath(),
            $this->gridStreamRouteMethod(),
            // WITHOUT the handler registry the route carries no handlers and the
            // re-run pipeline invokes nothing → an empty frame. Pass it so the
            // re-run re-runs THIS handler.
            $this->attributeDiscovery->getHandlerRegistry(),
        );
        if ($route === null) {
            return null;
        }

        $request = $payload->getHttpRequest();
        $snapshot = $request === null ? [] : [
            'method'  => $request->method,
            'uri'     => $request->uri,
            'headers' => $request->headers,
            'query'   => $request->query,
            'post'    => $request->post,
            'server'  => $request->server,
            'cookies' => $request->cookies,
            'content' => $request->content,
            'files'   => $request->files,
        ];

        // tenantContext is INTENTIONALLY null for the held-open grid re-run.
        // R4 re-invokes this handler IN THE SAME coroutine that already holds
        // the open fd — so the request's tenant context is ALREADY established
        // (and immutable for the life of the HTTP request). Passing a captured
        // context here makes RouteReRunner's TenantContextStore::set() throw
        // `TenantContextImmutableException`. The SubscriptionRecord still
        // carries the tenant id/blob (read-only) for cross-worker channel
        // scoping.
        return new ReRunContext(
            cachedDto: $payload,
            route: $route,
            requestSnapshot: $snapshot,
            sessionId: $sessionId,
            subjectRef: self::currentSubjectRef(),
            tenantContext: null,
        );
    }

    /**
     * Build the cross-worker subscription row ({@see SubscriptionRecord}) — the
     * identity-free, routable record the reverse-index scan filters on.
     * streamingId == sessionId (one stream per connection); scopeKeys is the
     * declared live-on-events watch list ({@see resolveWatchedScopeKeys()});
     * tenantId/tenantBlob mirror the publisher so the channel names agree.
     *
     * An empty `scopeKeys` (the grid declares no `#[GridFeed(liveOn:)]`) is a
     * valid record: R1 indexes no invalidation channel for it, so it never
     * live-re-runs (a static grid), yet the record + its worker-local
     * {@see ReRunContext} are still registered so the held-open stream's
     * view-change re-hydrate keeps working (that rides the context, not
     * scopeKeys).
     */
    private function buildSubscriptionRecord(string $sessionId): SubscriptionRecord
    {
        return new SubscriptionRecord(
            streamingId: $sessionId,
            sessionId: $sessionId,
            tenantId: self::currentTenantId(),
            scopeKeys: $this->resolveWatchedScopeKeys(),
            tenantBlob: self::currentTenantBlob(),
        );
    }

    /**
     * The live-on-events scope keys THIS feed's held-open stream subscribes to,
     * sourced from its `#[GridFeed(liveOn:)]` declaration (memoized per worker).
     * See {@see watchedScopeKeysForRoute()} for the resolution + default rules.
     *
     * @return list<string>
     */
    private function resolveWatchedScopeKeys(): array
    {
        $route = $this->gridStreamRoutePath();

        return self::$watchedScopeKeyCache[$route]
            ??= self::watchedScopeKeysForRoute(
                $this->classDiscovery->findClassesWithAttribute(GridFeed::class),
                $route,
            );
    }

    /**
     * Resolve the declared live-on-events watched scope keys for a feed route —
     * the single source of {@see SubscriptionRecord::$scopeKeys} (live-on-events
     * Phase 2). Scans the `#[GridFeed]`-bearing component classes, matches the
     * one whose declared {@see GridFeed::$route} equals the feed route this
     * handler serves, and returns its {@see GridFeed::$liveOn} list VERBATIM.
     * This REPLACES the former hardcoded per-handler `gridStreamWatchedScopeKey()`
     * seam: the watched scope is now DECLARED on the grid, not coded in the
     * handler.
     *
     * The list is the subscribe contract verbatim — `SubscriptionRecord::$scopeKeys`
     * is already a list, so `liveOn: [a, b, c]` subscribes the held-open stream
     * to all three: ANY firing re-runs (OR semantics), and a burst coalesces to
     * one re-run via the existing `RerunCoalescer`.
     *
     * Default `[]` — no `#[GridFeed]` matches the route, or it declares no
     * `liveOn` — means an EMPTY subscription: the stream watches no channel and
     * never live-re-runs (a static grid, today's behaviour). It is byte-equal to
     * the no-subscription state regardless of how the record is later indexed.
     *
     * @param list<class-string> $gridFeedClasses the component classes carrying `#[GridFeed]`
     * @return list<string>
     */
    public static function watchedScopeKeysForRoute(array $gridFeedClasses, string $routePath): array
    {
        foreach ($gridFeedClasses as $class) {
            if (!class_exists($class)) {
                continue;
            }
            $attrs = (new \ReflectionClass($class))->getAttributes(GridFeed::class);
            if ($attrs === []) {
                continue;
            }
            /** @var GridFeed $feed */
            $feed = $attrs[0]->newInstance();
            if ($feed->route === $routePath) {
                return array_values($feed->liveOn);
            }
        }

        return [];
    }

    /**
     * The frozen subject reference (the immutable-block anchor a re-auth
     * compares the live session's subject against). Best-effort: '' when no
     * subject is resolvable. Read defensively so a missing/renamed auth surface
     * degrades rather than fatals.
     */
    private static function currentSubjectRef(): string
    {
        $store = '\Semitexa\Auth\Context\AuthContextStore';
        if (class_exists($store) && method_exists($store, 'getUser')) {
            /** @var object|null $user */
            $user = $store::getUser();
            if (is_object($user) && method_exists($user, 'getId')) {
                return (string) $user->getId();
            }
        }

        return '';
    }

    private static function currentTenantId(): string
    {
        $tenant = self::resolveTenant();
        if (is_object($tenant) && method_exists($tenant, 'getTenantId')) {
            $id = trim((string) $tenant->getTenantId());
            if ($id !== '') {
                return $id;
            }
        }

        return 'default';
    }

    private static function currentTenantBlob(): string
    {
        $tenant = self::resolveTenant();
        $blob = null;
        if (is_object($tenant) && method_exists($tenant, 'forSerialization')) {
            $blob = $tenant->forSerialization();
        }

        return self::encode(is_array($blob) ? $blob : []);
    }

    /** Resolve the live tenant context defensively (null in non-tenancy paths). */
    private static function resolveTenant(): ?object
    {
        $ctx = '\Semitexa\Tenancy\Context\TenantContext';
        if (class_exists($ctx) && method_exists($ctx, 'get')) {
            $tenant = $ctx::get();

            return is_object($tenant) ? $tenant : null;
        }

        return null;
    }

    /** Content-negotiation: does the caller prefer an event stream? */
    protected function prefersSse(GridStreamPayloadInterface $payload): bool
    {
        $request = $payload->getHttpRequest();
        if ($request === null) {
            return false;
        }
        $accept = $request->getHeader('accept');
        if (!is_string($accept) || $accept === '') {
            return false;
        }
        return str_contains(strtolower($accept), 'text/event-stream');
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function jsonResponse(ResourceResponse $resource, int $status, array $body): ResourceResponse
    {
        return $resource
            ->setStatusCode($status)
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setContent(self::encode($body));
    }

    /**
     * @param array<string, mixed> $body
     */
    protected static function encode(array $body): string
    {
        try {
            return json_encode(
                $body,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException) {
            return '{"ok":false,"reason":"json_encode_failed"}';
        }
    }
}
