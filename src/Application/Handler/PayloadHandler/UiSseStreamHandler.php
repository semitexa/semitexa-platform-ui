<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Environment;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Request;
use Semitexa\Core\Server\SwooleBootstrap;
use Semitexa\PlatformUi\Application\Payload\Request\UiSseStreamPayload;
use Semitexa\PlatformUi\Application\Service\Event\AllowAllUiSseSubscriptionAuthorizer;
use Semitexa\PlatformUi\Application\Service\Event\InMemoryUiSseConnectionLimiter;
use Semitexa\PlatformUi\Application\Service\Event\UiSseChannelToken;
use Semitexa\PlatformUi\Application\Service\Event\UiSseConnectionLease;
use Semitexa\PlatformUi\Application\Service\Event\UiSseConnectionLimiterInterface;
use Semitexa\PlatformUi\Application\Service\Event\UiSsePatchQueue;
use Semitexa\PlatformUi\Application\Service\Event\UiSseSubscriptionAuthorizerInterface;
use Semitexa\PlatformUi\Application\Service\Event\UiSseSubscriptionContext;
use Semitexa\PlatformUi\Domain\Exception\UiInteractionException;
use Semitexa\PlatformUi\Domain\Exception\UiSseConnectionLimitException;
use Semitexa\PlatformUi\Domain\Exception\UiSseSubscriptionException;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;
use Swoole\Coroutine;
use Swoole\Http\Response as SwooleResponse;

/**
 * GET /__ui/stream — Platform UI SSE patch channel.
 *
 * Lifecycle (hardened ordering — earlier steps fail closed without
 * touching state later steps rely on):
 *
 *   1. Read `token` from query.
 *   2. Verify the token via UiSseChannelToken (HMAC + purpose claim).
 *   3. Build a UiSseSubscriptionContext from the verified claims.
 *   4. Run UiSseSubscriptionAuthorizerInterface. Denial → 403
 *      `subscription_forbidden`. The stream is never opened, the
 *      limiter is never consulted, no patches are read.
 *   5. Claim a connection lease via UiSseConnectionLimiterInterface.
 *      Cap hit → 429 `sse_connection_limit_exceeded`. Per-IP and
 *      global caps honor `SSE_MAX_CONN_PER_IP` /
 *      `SSE_MAX_CONN_GLOBAL` (defaults 5 / 500), reused from the SSR
 *      SSE service so operators tune one knob for both subsystems.
 *   6. Acquire the underlying Swoole request/response from
 *      SwooleBootstrap. In non-Swoole contexts (CLI tests, fastcgi,
 *      etc.) the handler raises 503 BEFORE writing headers.
 *   7. Set SSE headers and write a `connected` initial frame.
 *   8. Poll loop until disconnect / max-age:
 *      - drain up to BATCH_LIMIT messages from the queue;
 *      - write each as `event: ui.patch` SSE frame;
 *      - sleep POLL_INTERVAL_SECONDS via Swoole\Coroutine::sleep;
 *      - bail if connection_aborted() or the connection age exceeds
 *        MAX_AGE_SECONDS.
 *   9. Send a final `close` frame, then release the lease (always —
 *      try/finally on the loop, so connection_aborted, write_failed,
 *      max_age, and explicit close all release).
 *
 * The handler MUST NOT emit handler / class / method names in any SSE
 * frame. The publisher's wire shape is the only thing forwarded
 * downstream, and that shape is already constrained to safe
 * UiResponsePatch JSON.
 */
#[AsPayloadHandler(payload: UiSseStreamPayload::class, resource: ResourceResponse::class)]
final class UiSseStreamHandler implements TypedHandlerInterface
{
    /** Frames drained per poll tick. Bounded to keep one tick cheap. */
    private const BATCH_LIMIT = 32;

    /** Coroutine-friendly poll interval. */
    private const POLL_INTERVAL_SECONDS = 0.25;

    /**
     * Hard ceiling on a single connection's lifetime. Bounds
     * pathological clients holding sockets indefinitely. Falls back to
     * SSE_MAX_CONNECTION_AGE_SECONDS when present so the framework's
     * existing knob can tune this too.
     */
    private const DEFAULT_MAX_AGE_SECONDS = 600;

    #[InjectAsMutable]
    protected Request $request;

    #[InjectAsReadonly]
    protected UiSsePatchQueue $queue;

    #[InjectAsReadonly]
    protected UiSseSubscriptionAuthorizerInterface $authorizer;

    #[InjectAsReadonly]
    protected UiSseConnectionLimiterInterface $limiter;

    public function handle(UiSseStreamPayload $_payload, ResourceResponse $resource): ResourceResponse
    {
        try {
            return $this->doHandle($resource);
        } catch (UiInteractionException $e) {
            // Pre-stream rejections (401 missing/invalid token, 403
            // subscription_forbidden, 429 sse_connection_limit_exceeded,
            // 503 sse_unavailable). Render a safe JSON body — the SSE
            // stream has NOT been opened at this point, so the
            // response is still ours to write through the framework's
            // normal pipeline.
            return $this->jsonErrorResponse($resource, $e->httpStatus, $e->reason, $e->getMessage());
        }
    }

    private function doHandle(ResourceResponse $resource): ResourceResponse
    {
        // 1. Validate token presence + verify signature/purpose/TTL.
        $token = $this->request->getQuery('token');
        if ($token === '') {
            throw new UiSseSubscriptionException(
                'missing_channel_token',
                'SSE subscription requires a "token" query parameter.',
            );
        }
        $channelId = UiSseChannelToken::verifyChannelId($token);
        if ($channelId === null) {
            throw new UiSseSubscriptionException(
                'invalid_channel_token',
                'SSE subscription token failed verification.',
            );
        }

        // 2. Build the subscription context from the verified token.
        //    The raw signed claims are not exposed to userspace
        //    authorizers via headers/etc — only via $context->claims.
        $context = $this->buildContext($token, $channelId);

        // 3. Authorize the subscription BEFORE consuming a connection
        //    slot. Denied attempts cannot influence per-IP / global
        //    counters.
        if (!$this->resolveAuthorizer()->authorize($context)) {
            throw new UiSseSubscriptionException(
                'subscription_forbidden',
                'SSE subscription is not allowed.',
                httpStatus: 403,
            );
        }

        // 4. Claim a connection lease. Throws
        //    UiSseConnectionLimitException (429) on cap saturation;
        //    we let it propagate up to the framework's error mapper.
        $lease = $this->resolveLimiter()->claim($context);

        try {
            // 5. Grab the underlying Swoole response. Done AFTER all
            //    upstream checks so an unprivileged client never even
            //    sees response headers.
            $swooleCtx = $this->currentSwooleContext();
            if ($swooleCtx === null || ($swooleCtx[1] ?? null) === null) {
                throw new UiSseSubscriptionException(
                    'sse_unavailable',
                    'SSE transport is not available on this runtime.',
                    httpStatus: 503,
                );
            }
            /** @var SwooleResponse $swooleResponse */
            $swooleResponse = $swooleCtx[1];

            // 6. SSE headers + initial event.
            $swooleResponse->status(200);
            $swooleResponse->header('Content-Type', 'text/event-stream');
            $swooleResponse->header('Cache-Control', 'no-cache');
            $swooleResponse->header('Connection', 'keep-alive');
            $swooleResponse->header('X-Accel-Buffering', 'no');

            $this->writeFrame($swooleResponse, 'connected', [
                'v'         => 1,
                'channel'   => $channelId,
                'serverTs'  => time(),
            ]);

            // 7. Poll loop.
            $maxAge = $this->resolveMaxAge();
            $startedAt = time();
            $closeReason = 'client_disconnected';

            while (true) {
                if ($maxAge > 0 && (time() - $startedAt) >= $maxAge) {
                    $closeReason = 'max_age';
                    break;
                }
                if (function_exists('connection_aborted') && connection_aborted()) {
                    break;
                }

                $batch = $this->queue->drain($channelId, self::BATCH_LIMIT);
                foreach ($batch as $rawJson) {
                    if (!$this->writeRawFrame($swooleResponse, 'ui.patch', $rawJson)) {
                        $closeReason = 'write_failed';
                        break 2;
                    }
                }

                if (class_exists(Coroutine::class)) {
                    Coroutine::sleep(self::POLL_INTERVAL_SECONDS);
                } else {
                    usleep((int) (self::POLL_INTERVAL_SECONDS * 1_000_000));
                }
            }

            $this->writeFrame($swooleResponse, 'close', [
                'reason' => $closeReason,
            ]);
            @$swooleResponse->end();
        } finally {
            // Always release the lease — even on Swoole/runtime
            // exceptions thrown inside the loop. Per-IP and global
            // counters drop back down promptly; TTL eviction stays a
            // safety net rather than the only release path.
            $this->resolveLimiter()->release($lease);
        }

        $resource->setContent('');
        return $resource;
    }

    private function buildContext(string $token, string $channelId): UiSseSubscriptionContext
    {
        $claims = SignedContext::verify($token) ?? [];
        $purpose = is_string($claims['c'] ?? null) ? (string) $claims['c'] : UiSseChannelToken::PURPOSE_CLAIM;
        $iat = is_int($claims['iat'] ?? null) ? (int) $claims['iat'] : 0;
        $exp = is_int($claims['exp'] ?? null) ? (int) $claims['exp'] : 0;
        return new UiSseSubscriptionContext(
            channelId: $channelId,
            purpose:   $purpose,
            issuedAt:  $iat,
            expiresAt: $exp,
            requestIp: $this->resolveRequestIp(),
            claims:    $claims,
        );
    }

    private function resolveRequestIp(): string
    {
        // Prefer the Swoole-level remote_addr (what
        // AsyncResourceSseServer also reads). Fall back to whatever
        // the framework exposes via Request when running outside
        // Swoole — tests construct Request directly with $server.
        $context = $this->currentSwooleContext();
        if ($context !== null && is_array($context[0]->server ?? null)) {
            $server = $context[0]->server;
            $ip = trim((string) ($server['remote_addr'] ?? ''));
            if ($ip !== '') {
                return strtolower($ip);
            }
        }
        $serverArr = $this->request->server;
        $ip = trim((string) ($serverArr['remote_addr'] ?? ''));
        return $ip !== '' ? strtolower($ip) : '';
    }

    private function resolveAuthorizer(): UiSseSubscriptionAuthorizerInterface
    {
        if (!isset($this->authorizer)) {
            $this->authorizer = new AllowAllUiSseSubscriptionAuthorizer();
        }
        return $this->authorizer;
    }

    private function resolveLimiter(): UiSseConnectionLimiterInterface
    {
        if (!isset($this->limiter)) {
            $this->limiter = new InMemoryUiSseConnectionLimiter();
        }
        return $this->limiter;
    }

    /**
     * @return array{0: object, 1: object, 2?: object}|null
     */
    private function currentSwooleContext(): ?array
    {
        if (!class_exists(Coroutine::class)) {
            return null;
        }
        return SwooleBootstrap::getCurrentSwooleRequestResponse();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeFrame(SwooleResponse $response, string $event, array $data): bool
    {
        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        return $this->writeRawFrame($response, $event, $json);
    }

    private function writeRawFrame(SwooleResponse $response, string $event, string $jsonPayload): bool
    {
        $safeEvent = str_replace(["\r", "\n"], '', $event);
        $line = 'event: ' . $safeEvent . "\n"
              . 'data: ' . $jsonPayload . "\n\n";
        $result = @$response->write($line);
        return $result === true;
    }

    /**
     * Render a pre-stream rejection as safe JSON through the
     * framework's normal ResourceResponse pipeline. ONLY called when
     * the SSE stream has not started yet — once headers go out as
     * text/event-stream, we cannot replace them with application/json.
     */
    private function jsonErrorResponse(ResourceResponse $resource, int $status, string $reason, string $message): ResourceResponse
    {
        $body = [
            'ok'      => false,
            'reason'  => $reason,
            'message' => $message,
        ];
        try {
            $json = json_encode(
                $body,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException) {
            $json = '{"ok":false,"reason":"json_encode_failed"}';
            $status = 500;
        }
        return $resource
            ->setStatusCode($status)
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setContent($json);
    }

    private function resolveMaxAge(): int
    {
        $env = Environment::getEnvValue('SSE_MAX_CONNECTION_AGE_SECONDS');
        if ($env !== null && $env !== '' && ctype_digit($env)) {
            return (int) $env;
        }
        return self::DEFAULT_MAX_AGE_SECONDS;
    }

    /** Test seam — InjectAsMutable does not run in unit tests. */
    public function withRequest(Request $request): self
    {
        $this->request = $request;
        return $this;
    }

    public function withQueue(UiSsePatchQueue $queue): self
    {
        $this->queue = $queue;
        return $this;
    }

    public function withAuthorizer(UiSseSubscriptionAuthorizerInterface $authorizer): self
    {
        $this->authorizer = $authorizer;
        return $this;
    }

    public function withLimiter(UiSseConnectionLimiterInterface $limiter): self
    {
        $this->limiter = $limiter;
        return $this;
    }
}
